<?php

// Configuração do banco 
$dbHost = "localhost";
$dbPort = "5432"; //insira a porta do seu banco
$dbName = "desafio"; //insira o nome do seu banco
$dbUser = "postgres"; //insira o usuário do seu banco 
$dbPass = "1234"; //insira sua senha

// Token da API
$apiToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VySWQiOjI2ODQsInN0b3JlSWQiOjE5NzksImlhdCI6MTc1Mzk2MjIwOCwiZXhwIjoxNzU2Njg0Nzk5fQ.WlLjEihOHihKoznQkQLvVGIvYjJ4WmpoikSZmuTZ7oU";

// Conexão PDO
try {
    $pdo = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

$sql = "
    SELECT p.id AS pedido_id, p.valor_total, c.id AS cliente_id, c.nome, c.email, c.cpf_cnpj,
           c.data_nasc, pp.id AS pagamento_id, pp.num_cartao, pp.codigo_verificacao, 
           pp.vencimento, pp.nome_portador
    FROM pedidos p
    JOIN pedidos_pagamentos pp ON pp.id_pedido = p.id
    JOIN clientes c ON c.id = p.id_cliente
    JOIN lojas_gateway lg ON lg.id_loja = p.id_loja
    JOIN gateways g ON g.id = lg.id_gateway
    WHERE p.id_situacao = 1 -- aguardando pagamento
      AND pp.id_formapagto = 3 -- cartão de crédito
      AND g.descricao = 'PAGCOMPLETO'
";

$pedidos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

foreach ($pedidos as $pedido) {
    
    $date = DateTime::createFromFormat("Y-m", $pedido['vencimento']);
    $expiration = $date ? $date->format("my") : null; // mmYY

    $body = [
        "external_order_id" => intval($pedido['pedido_id']),
        "amount" => floatval($pedido['valor_total']),
        "card_number" => preg_replace('/\D/', '', $pedido['num_cartao']),
        "card_cvv" => strval($pedido['codigo_verificacao']),
        "card_expiration_date" => $expiration,
        "card_holder_name" => $pedido['nome_portador'],
        "customer" => [
            "external_id" => strval($pedido['cliente_id']),
            "name" => $pedido['nome'],
            "type" => "individual",
            "email" => $pedido['email'],
            "documents" => [
                [
                    "type" => "cpf",
                    "number" => $pedido['cpf_cnpj']
                ]
            ],
            "birthday" => date("Y-m-d", strtotime($pedido['data_nasc']))
        ]
    ];

    $url = "https://apiinterna.ecompleto.com.br/exams/processTransaction?accessToken={$apiToken}";

    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);


    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo "Erro cURL: " . curl_error($ch) . PHP_EOL;
        continue;
    }
    curl_close($ch);

    $decoded = json_decode($response, true);

    $pdo->beginTransaction();
    try {
        
        $stmt = $pdo->prepare("UPDATE pedidos_pagamentos SET retorno_intermediador = :ret WHERE id = :id");
        $stmt->execute([
            ":ret" => $response,
            ":id" => $pedido['pagamento_id']
        ]);

        if (isset($decoded['Error']) && $decoded['Error'] === false && $decoded['Transaction_code'] === "00") {
            $novoStatus = 2;
        } else {
            $novoStatus = 3;
        }

        $stmt = $pdo->prepare("UPDATE pedidos SET id_situacao = :status WHERE id = :id");
        $stmt->execute([
            ":status" => $novoStatus,
            ":id" => $pedido['pedido_id']
        ]);

        $pdo->commit();
        echo "Pedido {$pedido['pedido_id']} processado com sucesso. Retorno: " . $decoded['Message'] . PHP_EOL;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Erro ao atualizar pedido {$pedido['pedido_id']}: " . $e->getMessage() . PHP_EOL;
    }
}
