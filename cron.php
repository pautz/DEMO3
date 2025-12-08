<?php
// cron.php
// Atualiza saldo de aura continuamente, apenas das máquinas ativas
// Salva histórico de uso sem alternar status

date_default_timezone_set('America/Cuiaba');

$conn = new mysqli("localhost", "u839226731_cztuap", "Meu6595869Trator", "u839226731_meutrator");
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    die("Erro na conexão: " . $conn->connect_error);
}

// Busca todas as máquinas ativas com saldo > 0
$sql = "SELECT id, usuario, auras_por_hora, saldo_aura 
        FROM maquinas 
        WHERE status = 1 AND saldo_aura > 0";
$result = $conn->query($sql);

while ($m = $result->fetch_assoc()) {
    $id = $m['id'];
    $usuario = $m['usuario'];
    $consumoHora = floatval($m['auras_por_hora']);
    $saldo = floatval($m['saldo_aura']);

    // Pega o último inicio_gasto
    $stmt = $conn->prepare("SELECT id, inicio_gasto 
                            FROM historico_status 
                            WHERE maquina_id=? AND status_novo=1 
                            ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['inicio_gasto']) {
        $inicio = $row['inicio_gasto'];
        $idHist = $row['id'];

        // Calcula horas desde o início
        $horas = (strtotime(date("Y-m-d H:i:s")) - strtotime($inicio)) / 3600;
        if ($horas < 0) $horas = 0;

        // Consumo proporcional
       $totalGasto = ceil($horas * $consumoHora);

        // Atualiza saldo
        $novoSaldo = $saldo - $totalGasto;
        if ($novoSaldo < 0) $novoSaldo = 0;

        $stmtUp = $conn->prepare("UPDATE maquinas SET saldo_aura=?, ultima_atualizacao=NOW() WHERE id=?");
        $stmtUp->bind_param("di", $novoSaldo, $id);
        $stmtUp->execute();
        $stmtUp->close();

        // Atualiza histórico (fim_gasto e total_gasto)
        $stmtHist = $conn->prepare("UPDATE historico_status 
                                    SET fim_gasto=NOW(), total_gasto=? 
                                    WHERE id=?");
        $stmtHist->bind_param("di", $totalGasto, $idHist);
        $stmtHist->execute();
        $stmtHist->close();

        echo date("Y-m-d H:i:s") . " - Máquina $id atualizada. Saldo: $novoSaldo\n";
    }
}

$conn->close();
?>
