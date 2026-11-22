<?php
// admin/ajax_estoque.php – busca de peças do estoque para a OS (Fase 4: inclui lugar na estante e part number)
require_once '../config.php';
require_once '../includes/auth.php';
requireRole('admin','mecanico');
header('Content-Type: application/json');
$q=trim($_GET['q']??'');
if(strlen($q)<2){echo '[]';exit;}
try {
    $stmt=$pdo->prepare("SELECT e.id,e.codigo,e.nome,e.valor_custo,e.valor_venda,e.quantidade,e.foto,e.part_number,
            CONCAT(s.codigo, COALESCE(e.prateleira,'')) AS local
        FROM estoque e LEFT JOIN estoque_estantes s ON s.id=e.estante_id
        WHERE e.ativo=1 AND (e.nome LIKE ? OR e.codigo LIKE ? OR e.part_number LIKE ?) LIMIT 15");
    $stmt->execute(["%$q%","%$q%","%$q%"]);
} catch (PDOException $e) {
    // banco ainda sem a migração da Fase 4
    $stmt=$pdo->prepare("SELECT id,codigo,nome,valor_custo,valor_venda,quantidade,foto FROM estoque WHERE ativo=1 AND (nome LIKE ? OR codigo LIKE ?) LIMIT 15");
    $stmt->execute(["%$q%","%$q%"]);
}
echo json_encode($stmt->fetchAll());
