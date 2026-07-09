<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ObsidianDocument;
use App\Models\Tenant;
use Illuminate\Support\Str;

$docs = ObsidianDocument::withoutGlobalScopes()->withTrashed()->get();
echo "Found " . $docs->count() . " documents in DB.\n";

$cleanedCount = 0;
foreach ($docs as $doc) {
    $filename = $doc->filename;
    
    // 1. Detect Frontmatter Title from raw_content if not already saved in frontmatter
    $frontmatter = $doc->frontmatter ?? [];
    $title = $frontmatter['title'] ?? null;
    
    if (!$title) {
        $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        // Remove common prefixes
        $cleaned = preg_replace('/^(puesto|proceso|tarea|checklist|rutina|indicador|kpi|reglas|reglamento|formato|glosario|organizacion)[-_]/i', '', $filenameWithoutExt);
        // Replace dashes and underscores with spaces
        $cleaned = str_replace(['-', '_'], ' ', $cleaned);
        // Title Case capitalization
        $title = mb_convert_case($cleaned, MB_CASE_TITLE, "UTF-8");
    }

    // 2. Classify Type based on filename
    $type = $frontmatter['type'] ?? 'nota';
    $type = strtolower($type);
    $filePathLower = strtolower($filename);
    
    if ($type === 'nota') {
        if (str_contains($filePathLower, 'puesto') || str_contains($filePathLower, 'role')) {
            $type = 'puesto';
        } elseif (str_contains($filePathLower, 'proceso') || str_contains($filePathLower, 'sop') || str_contains($filePathLower, 'procedimiento')) {
            $type = 'proceso';
        } elseif (str_contains($filePathLower, 'checklist') || str_contains($filePathLower, 'lista') || str_contains($filePathLower, 'tarea')) {
            $type = 'tarea';
        } elseif (str_contains($filePathLower, 'rutina') || str_contains($filePathLower, 'diario') || str_contains($filePathLower, 'daily') || str_contains($filePathLower, 'semanal') || str_contains($filePathLower, 'mensual')) {
            $type = 'rutina';
        } elseif (str_contains($filePathLower, 'indicador') || str_contains($filePathLower, 'kpi') || str_contains($filePathLower, 'metrica') || str_contains($filePathLower, 'evaluacion')) {
            $type = 'indicador';
        } elseif (str_contains($filePathLower, 'regla') || str_contains($filePathLower, 'reglamento') || str_contains($filePathLower, 'sancion') || str_contains($filePathLower, 'norma') || str_contains($filePathLower, 'politica') || str_contains($filePathLower, 'conducta')) {
            $type = 'reglas';
        } elseif (str_contains($filePathLower, 'formato') || str_contains($filePathLower, 'plantilla') || str_contains($filePathLower, 'documento')) {
            $type = 'formatos';
        } elseif (str_contains($filePathLower, 'glosario') || str_contains($filePathLower, 'terminos') || str_contains($filePathLower, 'definiciones') || str_contains($filePathLower, 'conceptos')) {
            $type = 'glosario';
        } elseif (str_contains($filePathLower, 'organizacion') || str_contains($filePathLower, 'empresa') || str_contains($filePathLower, 'historia') || str_contains($filePathLower, 'mision') || str_contains($filePathLower, 'vision') || str_contains($filePathLower, 'valores') || str_contains($filePathLower, 'bienvenida') || str_contains($filePathLower, 'inicio') || str_contains($filePathLower, 'readme')) {
            $type = 'organizacion';
        }
    }

    // 3. Map Icon
    $icon = $frontmatter['icon'] ?? null;
    if (!$icon) {
        if ($type === 'puesto') $icon = 'briefcase';
        elseif ($type === 'proceso') $icon = 'repeat';
        elseif ($type === 'tarea') $icon = 'check-square';
        elseif ($type === 'rutina') $icon = 'clock';
        elseif ($type === 'indicador') $icon = 'trophy';
        elseif ($type === 'reglas') $icon = 'clipboard-list';
        elseif ($type === 'formatos') $icon = 'settings';
        elseif ($type === 'glosario') $icon = 'book-open';
        elseif ($type === 'organizacion') $icon = 'building-2';
        else $icon = 'file-text';
    }

    $doc->update([
        'title' => $title,
        'type' => $type,
        'icon' => $icon
    ]);

    echo "Updated Doc ID: {$doc->id} | Title: {$title} | Type: {$type} | Icon: {$icon}\n";
    $cleanedCount++;
}

// Rebuild links for all tenants
$tenants = Tenant::withoutGlobalScopes()->get();
foreach ($tenants as $t) {
    $controller = new \App\Http\Controllers\ObsidianController();
    $method = new ReflectionMethod(\App\Http\Controllers\ObsidianController::class, 'rebuildVaultLinks');
    $method->setAccessible(true);
    $method->invoke($controller, $t->id);
    echo "Rebuilt links for Tenant ID: {$t->id}\n";
}

echo "Successfully cleaned and cataloged {$cleanedCount} documents.\n";
