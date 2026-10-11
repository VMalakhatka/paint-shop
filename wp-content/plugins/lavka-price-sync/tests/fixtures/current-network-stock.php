<?php
function current_stock_fixture(array $query, array $graph): array {
    $cards = [];
    foreach ($graph['nodes'] as $node) foreach ($query['warehouseIds'] as $id) $cards[] = [
        'sku' => $node['sku'], 'warehouseId' => $id, 'status' => 'CAPTURED', 'physicalQuantity' => 10,
        'availableQuantity' => 6, 'reservedQuantity' => 4, 'internalTransferAccounts' => [],
        'orderPolicy' => ['minimumStock'=>1,'maximumStock'=>9999,'orderAllowed'=>true,'reserveAboveForecast'=>1,'maximumStockLimited'=>false,'validationState'=>'VALID'],
    ];
    $analysis = $query['analysisWarehouseIds']; sort($analysis, SORT_NUMERIC);
    return ['ok'=>true,'version'=>1,'captureId'=>'12345678-abcd-4321-abcd-123456789012', 'method'=>'FOLIO_SERIALIZABLE_READ',
        'sourceDatabase'=>$query['sourceDatabase'],'warehouseIds'=>$query['warehouseIds'],'analysisWarehouseIds'=>$analysis,
        'startedAt'=>'2026-10-11T12:00:00Z','capturedAt'=>'2026-10-11T12:00:01Z','assemblyGraph'=>$graph,'cards'=>$cards];
}
