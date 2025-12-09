<?php
// cron.php
// Atualiza saldo de aura continuamente, apenas das máquinas ativas
// Desconto proporcional por segundo

error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('America/Cuiaba');

$conn = new mysqli("localhost", "u839226731_cztuap", "Meu6595869Trator", "u839226731_meutrator");
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    die("Erro na conexão: " . $conn->connect_error);
}

// Busca todas as máquinas ativas com saldo > 0
$sql = "SELECT id, usuario, auras_por_hora, saldo_aura, ultima_atualizacao 
        FROM maquinas 
        WHERE status = 1 AND saldo_aura > 0";
$result = $conn->query($sql);

while ($m = $result->fetch_assoc()) {
    $id          = intval($m['id']);
    $usuario     = $m['usuario'];
    $consumoHora = floatval($m['auras_por_hora']); // taxa de consumo em auras/hora
    $saldo       = floatval($m['saldo_aura']);
    $ultimaAtual = $m['ultima_atualizacao'] ?? null;

    // Se não houver ultima_atualizacao, usa inicio_gasto
    if (!$ultimaAtual) {
        $stmt = $conn->prepare("SELECT inicio_gasto FROM historico_status 
                                WHERE maquina_id=? AND status_novo=1 
                                ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $ultimaAtual = $row ? $row['inicio_gasto'] : null;
    }

    if ($ultimaAtual) {
        // Usa NOW() do MySQL para garantir mesmo fuso
        $stmtNow = $conn->query("SELECT NOW() as agora");
        $agora = $stmtNow->fetch_assoc()['agora'];
        $stmtNow->close();

        // Calcula segundos desde a última atualização
        $segundos = (strtotime($agora) - strtotime($ultimaAtual));
        if ($segundos < 0) $segundos = 0;

        // Consumo proporcional por segundo
        $gastoAura = $segundos * ($consumoHora / 3600);

        // Atualiza saldo
        $novoSaldo = $saldo - $gastoAura;
        if ($novoSaldo < 0) $novoSaldo = 0;

        // Atualiza saldo e ultima_atualizacao
        $stmtUp = $conn->prepare("UPDATE maquinas SET saldo_aura=?, ultima_atualizacao=NOW() WHERE id=?");
        $stmtUp->bind_param("di", $novoSaldo, $id);
        $stmtUp->execute();
        $stmtUp->close();

        // Atualiza histórico acumulando o gasto
        $stmtHist = $conn->prepare("UPDATE historico_status 
                                    SET total_gasto = total_gasto + ? 
                                    WHERE maquina_id=? AND status_novo=1 
                                    ORDER BY id DESC LIMIT 1");
        $stmtHist->bind_param("di", $gastoAura, $id);
        $stmtHist->execute();
        $stmtHist->close();

        // Se saldo zerou, inativa a máquina
        if ($novoSaldo <= 0) {
            $stmtInativa = $conn->prepare("UPDATE maquinas SET status=0 WHERE id=?");
            $stmtInativa->bind_param("i", $id);
            $stmtInativa->execute();
            $stmtInativa->close();

            echo date("Y-m-d H:i:s") . " - Máquina $id INATIVADA (saldo zerado).\n";
        } else {
            echo date("Y-m-d H:i:s") . " - Máquina $id atualizada. Intervalo: $segundos s | Gasto Aura: $gastoAura | Saldo: $novoSaldo\n";
        }
    }
}

$conn->close();
?>
