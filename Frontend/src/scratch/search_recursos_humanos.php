<?php
$file = 'c:/Users/Servidor/Desktop/Talent360/Frontend/src/components/RecursosHumanos.tsx';
$lines = file($file);
$queries = ['Modal', 'Editar', 'Colaborador', 'tabs', 'Tab', 'activeTab', 'formulario'];

foreach ($queries as $query) {
    echo "=== Query: $query ===\n";
    $count = 0;
    foreach ($lines as $i => $line) {
        if (stripos($line, $query) !== false) {
            echo ($i + 1) . ": " . trim($line) . "\n";
            $count++;
            if ($count > 40) {
                echo "... truncated ...\n";
                break;
            }
        }
    }
}
