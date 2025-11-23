<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== 'motorista') {
    header('Location: index.html');
    exit;
}

require_once 'config.php';
$database = new Database();
$db = $database->getConnection();

// Buscar equipe do motorista
$query_equipe = "SELECT e.*, v.placa, v.modelo 
                FROM equipes e 
                JOIN veiculos v ON e.veiculo_id = v.id 
                WHERE e.motorista_id = ? AND e.status != 'manutencao'";
$stmt_equipe = $db->prepare($query_equipe);
$stmt_equipe->execute([$_SESSION['user_id']]);
$equipe = $stmt_equipe->fetch(PDO::FETCH_ASSOC);

// Buscar funcionários disponíveis para esta equipe
$query_funcionarios = "SELECT id, nome FROM usuarios WHERE tipo = 'funcionario' AND ativo = TRUE";
$stmt_funcionarios = $db->prepare($query_funcionarios);
$stmt_funcionarios->execute();
$funcionarios_disponiveis = $stmt_funcionarios->fetchAll(PDO::FETCH_ASSOC);

// Processar localização do motorista
$lat_motorista = $_GET['lat'] ?? -22.9789;
$lng_motorista = $_GET['lng'] ?? -49.8716;

// Buscar rota atual
$rota_atual = null;
$relatos_rota = [];
if ($equipe) {
    $query_rota = "SELECT * FROM rotas WHERE equipe_id = ? AND status IN ('planejada', 'iniciada') ORDER BY id DESC LIMIT 1";
    $stmt_rota = $db->prepare($query_rota);
    $stmt_rota->execute([$equipe['id']]);
    $rota_atual = $stmt_rota->fetch(PDO::FETCH_ASSOC);
    
    if ($rota_atual) {
        $relatos_ids = json_decode($rota_atual['relatos_ids'], true);
        if (!empty($relatos_ids)) {
            $placeholders = str_repeat('?,', count($relatos_ids) - 1) . '?';
            
            // Buscar relatos com cálculo de distância - CORREÇÃO APPLICADA
            $query_relatos = "SELECT r.*, 
                             (6371 * acos(cos(radians(?)) * cos(radians(r.latitude)) * cos(radians(r.longitude) - radians(?)) + sin(radians(?)) * sin(radians(r.latitude)))) AS distancia
                             FROM relatos r 
                             WHERE r.id IN ($placeholders) 
                             ORDER BY 
                                 CASE WHEN r.nivel_emergencia = 3 THEN 1 
                                      WHEN r.nivel_emergencia = 2 THEN 2 
                                      ELSE 3 END,
                                 distancia ASC";
            
            $params = array_merge([$lat_motorista, $lng_motorista, $lat_motorista], $relatos_ids);
            $stmt_relatos = $db->prepare($query_relatos);
            $stmt_relatos->execute($params);
            $relatos_rota = $stmt_relatos->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Obter estatísticas do motorista - CORREÇÃO APPLICADA
$stats = ['total_relatos' => 0, 'relatos_resolvidos' => 0, 'relatos_andamento' => 0];
if ($equipe) {
    $query_stats = "SELECT 
        COUNT(*) as total_relatos,
        SUM(CASE WHEN status = 'concluido' THEN 1 ELSE 0 END) as relatos_resolvidos,
        SUM(CASE WHEN status = 'em_rota' THEN 1 ELSE 0 END) as relatos_andamento
        FROM relatos WHERE equipe_id = ?";
    $stmt_stats = $db->prepare($query_stats);
    $stmt_stats->execute([$equipe['id']]);
    $stats_result = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    if ($stats_result) {
        $stats = $stats_result;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Motorista - Sistema de Emergências</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Arial, sans-serif; }
        body { background: #f8f9fa; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        header { background: #2c3e50; color: white; padding: 1rem 0; }
        .header-content { display: flex; justify-content: space-between; align-items: center; }
        .dashboard-card { background: white; border-radius: 8px; padding: 20px; margin: 20px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; margin: 5px; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: black; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-primary { background: #1e3c72; color: white; }
        .btn:disabled { background: #6c757d; cursor: not-allowed; }
        .rota-item { border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin: 10px 0; }
        .rota-item.atual { border-left: 4px solid #28a745; background: #e8f5e8; }
        .rota-item.proximo { border-left: 4px solid #ffc107; background: #fff3cd; }
        .emergency-3 { border-left: 4px solid #e74c3c !important; }
        .emergency-2 { border-left: 4px solid #f39c12 !important; }
        .gps-container { height: 500px; background: #e9ecef; border-radius: 8px; margin: 20px 0; position: relative; }
        #map { height: 100%; width: 100%; border-radius: 8px; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; }
        .status-disponivel { background: #d4edda; color: #155724; }
        .status-em_rota { background: #fff3cd; color: #856404; }
        .equipe-section { background: #e8f4f8; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .form-group { margin-bottom: 15px; }
        .form-label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .funcionario-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0; }
        .funcionario-item { background: white; padding: 10px; border-radius: 4px; text-align: center; border: 1px solid #ddd; }
        .map-controls { position: absolute; top: 10px; right: 10px; z-index: 1000; }
        .distance-badge { background: #007bff; color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.8rem; }
        .priority-badge { padding: 3px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: bold; }
        .priority-3 { background: #e74c3c; color: white; }
        .priority-2 { background: #f39c12; color: white; }
        .priority-1 { background: #27ae60; color: white; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 2rem; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9rem; opacity: 0.9; }
        .motorista-info { background: white; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>Dashboard - Motorista</h1>
                <div>
                    <span>Bem-vindo, <?php echo $_SESSION['user_nome']; ?></span>
                    <span class="status-badge <?php echo $equipe ? 'status-' . $equipe['status'] : 'status-disponivel'; ?>" style="margin-left: 15px;">
                        <?php echo $equipe ? ucfirst(str_replace('_', ' ', $equipe['status'])) : 'Sem equipe'; ?>
                    </span>
                    <button onclick="window.location.href='logout.php'" style="margin-left: 20px; background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">Sair</button>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Informações do Motorista -->
        <div class="motorista-info">
            <h3><i class="fas fa-id-card"></i> Informações do Motorista</h3>
            <p><strong>Nome:</strong> <?php echo $_SESSION['user_nome']; ?></p>
            <?php if ($equipe): ?>
                <p><strong>Veículo:</strong> <?php echo $equipe['modelo']; ?> - <?php echo $equipe['placa']; ?></p>
                <p><strong>Status:</strong> 
                    <span class="status-badge status-<?php echo $equipe['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $equipe['status'])); ?>
                    </span>
                </p>
            <?php endif; ?>
        </div>

        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_relatos']; ?></div>
                <div class="stat-label">Total de Relatos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['relatos_resolvidos']; ?></div>
                <div class="stat-label">Resolvidos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['relatos_andamento']; ?></div>
                <div class="stat-label">Em Andamento</div>
            </div>
        </div>

        <?php if (!$equipe): ?>
            <div class="dashboard-card">
                <h2>Sem Equipe Atribuída</h2>
                <p>Você não está atribuído a nenhuma equipe no momento. Aguarde a designação do administrador.</p>
            </div>
        <?php else: ?>
            <!-- SEÇÃO: GERENCIAR EQUIPE (Só aparece quando NÃO tem rota ativa) -->
            <?php if (!$rota_atual || $rota_atual['status'] === 'planejada'): ?>
            <div class="dashboard-card">
                <h2>Minha Equipe - <?php echo $equipe['modelo']; ?> (<?php echo $equipe['placa']; ?>)</h2>
                
                <div class="equipe-section">
                    <h3>👥 Gerenciar Equipe</h3>
                    <p>Selecione os funcionários que estarão com você nesta rota:</p>
                    
                    <form id="formGerenciarEquipe">
                        <div class="funcionario-list">
                            <?php foreach ($funcionarios_disponiveis as $funcionario): ?>
                                <div class="funcionario-item">
                                    <label>
                                        <input type="checkbox" name="funcionarios[]" value="<?php echo $funcionario['id']; ?>" 
                                            <?php 
                                            if ($equipe['funcionario1_id'] == $funcionario['id'] || 
                                                $equipe['funcionario2_id'] == $funcionario['id'] || 
                                                $equipe['funcionario3_id'] == $funcionario['id']) echo 'checked'; 
                                            ?>>
                                        <?php echo $funcionario['nome']; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <button type="submit" class="btn btn-success" style="margin-top: 15px;">Salvar Equipe</button>
                    </form>
                </div>

                <?php if ($rota_atual): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
                        <h3>Rota <?php echo ucfirst($rota_atual['status']); ?></h3>
                        <button class="btn btn-success" onclick="iniciarRota()">Iniciar Rota</button>
                    </div>
                <?php else: ?>
                    <div class="equipe-section">
                        <p>✅ Equipe configurada. Aguarde o administrador atribuir uma rota.</p>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- SEÇÃO: ROTA EM ANDAMENTO (Só aparece quando tem rota iniciada) -->
            <?php if ($rota_atual && $rota_atual['status'] === 'iniciada'): ?>
            <div class="dashboard-card">
                <h2>Rota em Andamento - <?php echo $equipe['modelo']; ?> (<?php echo $equipe['placa']; ?>)</h2>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3>📍 Rota Iniciada</h3>
                    <button class="btn btn-danger" onclick="finalizarRota()">Finalizar Rota</button>
                </div>

                <!-- MAPA GPS FUNCIONAL -->
                <div class="gps-container">
                    <div id="map"></div>
                    <div class="map-controls">
                        <button class="btn btn-primary btn-sm" onclick="atualizarLocalizacao()">
                            📍 Atualizar Localização
                        </button>
                    </div>
                </div>

                <h3>Pontos da Rota (Ordenados por Prioridade e Distância):</h3>
                <?php if (count($relatos_rota) > 0): ?>
                    <?php foreach ($relatos_rota as $index => $relato): ?>
                        <div class="rota-item emergency-<?php echo $relato['nivel_emergencia']; ?> 
                            <?php echo $index === 0 ? 'atual' : ''; ?>
                            <?php echo $index === 1 ? 'proximo' : ''; ?>">
                            <h4>
                                <?php if ($index === 0): ?>
                                    🟢 ATUAL - 
                                <?php elseif ($index === 1): ?>
                                    🟡 PRÓXIMO - 
                                <?php endif; ?>
                                Ponto <?php echo $index + 1; ?> - Nível <?php echo $relato['nivel_emergencia']; ?>
                                <span class="distance-badge" style="margin-left: 10px;">
                                    📍 <?php echo isset($relato['distancia']) ? number_format($relato['distancia'], 2) . ' km' : 'Dist. N/A'; ?>
                                </span>
                            </h4>
                            <p><strong>Local:</strong> <?php echo $relato['bairro']; ?>, <?php echo $relato['rua'] ?? $relato['endereco']; ?></p>
                            <p><strong>Serviço:</strong> 
                                <?php 
                                $servicos = [
                                    'corte_arvore' => 'Corte de Árvore',
                                    'poda' => 'Poda',
                                    'recolher_galhos' => 'Recolher Galhos'
                                ];
                                echo $servicos[$relato['tipo_servico']] ?? $relato['tipo_servico'];
                                ?>
                            </p>
                            <p><strong>Descrição:</strong> <?php echo $relato['descricao']; ?></p>
                            
                            <?php if ($index === 0): ?>
                                <button class="btn btn-success" onclick="marcarComoExecutado(<?php echo $relato['id']; ?>)">
                                    ✅ Marcar como Executado
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Nenhum ponto na rota.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script>
        // Inicializar mapa
        const map = L.map('map').setView([<?php echo $lat_motorista; ?>, <?php echo $lng_motorista; ?>], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        
        // Marcador da posição atual do motorista
        let motoristaMarker = L.marker([<?php echo $lat_motorista; ?>, <?php echo $lng_motorista; ?>])
            .addTo(map)
            .bindPopup(`<b>📍 Sua Localização</b><br><?php echo $_SESSION['user_nome']; ?>`)
            .openPopup();

        // Adicionar relatos ao mapa
        <?php if (!empty($relatos_rota)): ?>
            <?php foreach ($relatos_rota as $index => $relato): ?>
                <?php if (!empty($relato['latitude']) && !empty($relato['longitude'])): ?>
                    const marker<?php echo $relato['id']; ?> = L.marker([<?php echo $relato['latitude']; ?>, <?php echo $relato['longitude']; ?>])
                        .addTo(map)
                        .bindPopup(`
                            <div style="min-width: 200px;">
                                <h4>📍 Ponto <?php echo $index + 1; ?></h4>
                                <p><strong>Local:</strong> <?php echo $relato['bairro']; ?>, <?php echo $relato['rua'] ?? $relato['endereco']; ?></p>
                                <p><strong>Serviço:</strong> <?php 
                                    $servicos = [
                                        'corte_arvore' => 'Corte de Árvore',
                                        'poda' => 'Poda', 
                                        'recolher_galhos' => 'Recolher Galhos'
                                    ];
                                    echo $servicos[$relato['tipo_servico']] ?? $relato['tipo_servico'];
                                ?></p>
                                <p><strong>Distância:</strong> <?php echo isset($relato['distancia']) ? number_format($relato['distancia'], 2) . ' km' : 'N/A'; ?></p>
                                <p><strong>Emergência:</strong> Nível <?php echo $relato['nivel_emergencia']; ?></p>
                                <?php if ($index === 0): ?>
                                    <button class="btn btn-success btn-sm" onclick="marcarComoExecutado(<?php echo $relato['id']; ?>)">
                                        ✅ Executado
                                    </button>
                                <?php endif; ?>
                            </div>
                        `);
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

        // Atualizar localização
        function atualizarLocalizacao() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    // Atualizar marcador
                    motoristaMarker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 13);
                    
                    // Atualizar URL com nova localização para RECALCULAR ROTA
                    window.location.href = `motoristadashboard.php?lat=${lat}&lng=${lng}`;
                }, function(error) {
                    alert('Erro ao obter localização: ' + error.message);
                });
            } else {
                alert('Geolocalização não é suportada por este navegador.');
            }
        }

        // Gerenciar equipe
        document.getElementById('formGerenciarEquipe').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const funcionariosSelecionados = Array.from(document.querySelectorAll('input[name="funcionarios[]"]:checked'))
                .map(input => input.value);
            
            if (funcionariosSelecionados.length === 0) {
                alert('Selecione pelo menos um funcionário para a equipe.');
                return;
            }

            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'gerenciar_equipe',
                    equipe_id: <?php echo $equipe ? $equipe['id'] : 'null'; ?>,
                    funcionarios: funcionariosSelecionados
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Equipe atualizada com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + data.message);
                }
            });
        });

        function iniciarRota() {
            if (confirm('Deseja iniciar a rota? O sistema começará a calcular o tempo de atendimento.')) {
                fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'iniciar_rota',
                        rota_id: <?php echo $rota_atual ? $rota_atual['id'] : 'null'; ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Rota iniciada com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                });
            }
        }

        function finalizarRota() {
            if (confirm('Deseja finalizar a rota? Todos os pontos devem estar concluídos.')) {
                fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'finalizar_rota',
                        rota_id: <?php echo $rota_atual ? $rota_atual['id'] : 'null'; ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Rota finalizada com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                });
            }
        }

        function marcarComoExecutado(relatoId) {
            if (confirm('Marcar este serviço como executado?')) {
                fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'marcar_executado',
                        relato_id: relatoId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Serviço marcado como executado!');
                        location.reload();
                    } else {
                        alert('Erro: ' + data.message);
                    }
                });
            }
        }

        // Atualizar localização automaticamente a cada 2 minutos para RECALCULAR ROTA
        setInterval(() => {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    // Atualizar marcador silenciosamente
                    motoristaMarker.setLatLng([lat, lng]);
                    
                    // Recarregar a página para RECALCULAR a rota com nova localização
                    window.location.href = `motoristadashboard.php?lat=${lat}&lng=${lng}`;
                });
            }
        }, 120000); // 2 minutos
    </script>
</body>
</html>
