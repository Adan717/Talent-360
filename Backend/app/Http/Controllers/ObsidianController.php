<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ObsidianVault;
use App\Models\ObsidianDocument;
use App\Models\ObsidianLink;
use App\Models\ObsidianSuggestion;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\ObsidianUser;
use App\Models\ObsidianReadProgress;

class ObsidianController extends Controller
{
    /**
     * Get or create the vault configuration for the current tenant.
     */
    private function getOrCreateVault()
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $vault = ObsidianVault::where('tenant_id', $tenantId)->first();
        if (!$vault) {
            $vault = ObsidianVault::create([
                'tenant_id' => $tenantId,
                'name' => 'Baúl Organizacional',
                'local_path' => null,
            ]);
        }
        return $vault;
    }

    /**
     * Get vault configurations & general status.
     */
    public function getSettings()
    {
        $vault = $this->getOrCreateVault();
        return response()->json($vault);
    }

    /**
     * Save vault configurations.
     */
    public function saveSettings(Request $request)
    {
        $vault = $this->getOrCreateVault();
        $request->validate([
            'name' => 'required|string|max:255',
            'local_path' => 'nullable|string|max:1024',
            'hide_oracle_button' => 'nullable|boolean',
        ]);

        $vault->update([
            'name' => $request->name,
            'local_path' => $request->local_path,
            'hide_oracle_button' => (bool) $request->hide_oracle_button,
        ]);

        return response()->json(['message' => 'Configuración guardada con éxito', 'vault' => $vault]);
    }

    /**
     * Scan local directory and sync documents.
     */
    public function syncLocal(Request $request)
    {
        $vault = $this->getOrCreateVault();
        $path = $request->input('local_path') ?? $vault->local_path;

        if (!$path || !File::isDirectory($path)) {
            return response()->json(['message' => 'El directorio especificado no es válido o no existe en el servidor.'], 400);
        }

        try {
            $files = File::allFiles($path);
            $mdFiles = array_filter($files, function ($file) {
                return $file->getExtension() === 'md';
            });

            $processedDocs = $this->processMarkdownFiles($mdFiles, $vault);

            return response()->json([
                'message' => 'Sincronización local exitosa.',
                'count' => count($processedDocs)
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error al sincronizar: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Upload ZIP file and sync documents.
     */
    public function syncZip(Request $request)
    {
        $vault = $this->getOrCreateVault();
        $request->validate([
            'zip_file' => 'required|file|mimes:zip|max:20480', // Max 20MB
        ]);

        if (!class_exists('ZipArchive')) {
            return response()->json(['message' => 'La extensión ZipArchive de PHP no está disponible en este servidor.'], 500);
        }

        $zipFile = $request->file('zip_file');
        $tempDir = storage_path('app/temp_obsidian_' . uniqid());
        
        try {
            $zip = new \ZipArchive;
            if ($zip->open($zipFile->getRealPath()) === TRUE) {
                $zip->extractTo($tempDir);
                $zip->close();

                $files = File::allFiles($tempDir);
                $mdFiles = array_filter($files, function ($file) {
                    return $file->getExtension() === 'md';
                });

                $processedDocs = $this->processMarkdownFiles($mdFiles, $vault);

                // Clean up
                File::deleteDirectory($tempDir);

                return response()->json([
                    'message' => 'Carga y sincronización de archivo ZIP exitosa.',
                    'count' => count($processedDocs)
                ]);
            } else {
                return response()->json(['message' => 'No se pudo abrir el archivo ZIP.'], 400);
            }
        } catch (\Exception $e) {
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
            return response()->json(['message' => 'Error al procesar el archivo ZIP: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Process parsed md files.
     */
    private function processMarkdownFiles($files, ObsidianVault $vault)
    {
        $tenantId = $vault->tenant_id;
        $activeSlugs = [];

        return DB::transaction(function () use ($files, $vault, $tenantId, &$activeSlugs) {
            $parsedDocs = [];

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $rawContent = File::get($file->getRealPath());

                // Parse Frontmatter
                $frontmatter = [];
                $content = $rawContent;
                if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)/s', $rawContent, $matches)) {
                    $yaml = $matches[1];
                    $content = $matches[2];

                    foreach (explode("\n", $yaml) as $line) {
                        $parts = explode(":", $line, 2);
                        if (count($parts) === 2) {
                            $key = trim($parts[0]);
                            $value = trim($parts[1]);
                            $value = preg_replace('/^["\'](.*)["\']$/', '$1', $value);
                            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                                $value = array_map('trim', explode(',', substr($value, 1, -1)));
                            }
                            $frontmatter[$key] = $value;
                        }
                    }
                           // Extracción de metadatos básicos con limpiador inteligente d                // Extracción de metadatos básicos con limpiador inteligente de títulos
                $title = $frontmatter['title'] ?? null;
                if (!$title) {
                    $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
                    // Remove common prefixes and abbreviations (pue, proc, tare, rut, form, vac, etc.) followed by numbers or symbols
                    $cleaned = preg_replace('/^(pue|proc|tare|rut|form|vac|puesto|proceso|tarea|checklist|rutina|indicador|kpi|reglas|reglamento|formato|glosario|organizacion)s?[-\s\d_]*/i', '', $filenameWithoutExt);
                    // Replace dashes and underscores with spaces
                    $cleaned = str_replace(['-', '_'], ' ', $cleaned);
                    // Trim extra spaces
                    $cleaned = trim($cleaned);
                    // Title Case capitalization
                    $title = mb_convert_case($cleaned, MB_CASE_TITLE, "UTF-8");
                }

                $slug = Str::slug($title);
                if (empty($slug)) {
                    $slug = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
                }

                // Si por alguna razón colisiona el slug en esta misma tanda, le agregamos número
                $baseSlug = $slug;
                $counter = 1;
                while (in_array($slug, $activeSlugs)) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $activeSlugs[] = $slug;

                // Determinar tipo con nuevos bloques jerárquicos y abreviaciones de archivo
                $type = $frontmatter['type'] ?? 'nota';
                $type = strtolower($type);
                $filePathLower = strtolower($file->getRelativePathname());

                // Si el archivo contiene "anexo", siempre es de tipo "anexo" (solicitud del usuario)
                if (str_contains($filePathLower, 'anexo')) {
                    $type = 'anexo';
                }

                if ($type === 'nota') {
                    if (str_contains($filePathLower, 'organizacion') || str_contains($filePathLower, 'empresa') || str_contains($filePathLower, 'historia') || str_contains($filePathLower, 'mision') || str_contains($filePathLower, 'vision') || str_contains($filePathLower, 'valores') || str_contains($filePathLower, 'bienvenida') || str_contains($filePathLower, 'inicio') || str_contains($filePathLower, 'readme')) {
                        $type = 'organizacion';
                    } elseif (str_contains($filePathLower, 'rutina') || str_contains($filePathLower, 'diario') || str_contains($filePathLower, 'daily') || str_contains($filePathLower, 'semanal') || str_contains($filePathLower, 'mensual') || str_contains($filePathLower, 'rut ')) {
                        $type = 'rutina';
                    } elseif (str_contains($filePathLower, 'checklist') || str_contains($filePathLower, 'lista') || str_contains($filePathLower, 'tarea') || str_contains($filePathLower, 'tare ')) {
                        $type = 'tarea';
                    } elseif (str_contains($filePathLower, 'indicador') || str_contains($filePathLower, 'kpi') || str_contains($filePathLower, 'metrica') || str_contains($filePathLower, 'evaluacion')) {
                        $type = 'indicador';
                    } elseif (str_contains($filePathLower, 'regla') || str_contains($filePathLower, 'reglamento') || str_contains($filePathLower, 'sancion') || str_contains($filePathLower, 'norma') || str_contains($filePathLower, 'politica') || str_contains($filePathLower, 'conducta')) {
                        $type = 'reglas';
                    } elseif (str_contains($filePathLower, 'formato') || str_contains($filePathLower, 'plantilla') || str_contains($filePathLower, 'documento') || str_contains($filePathLower, 'form ')) {
                        $type = 'formatos';
                    } elseif (str_contains($filePathLower, 'glosario') || str_contains($filePathLower, 'terminos') || str_contains($filePathLower, 'definiciones') || str_contains($filePathLower, 'conceptos')) {
                        $type = 'glosario';
                    } elseif (str_contains($filePathLower, 'puesto') || str_contains($filePathLower, 'role') || str_contains($filePathLower, 'pue ') || str_contains($filePathLower, 'administrador') || str_contains($filePathLower, 'gerente') || str_contains($filePathLower, 'supervisor') || str_contains($filePathLower, 'ayudante') || str_contains($filePathLower, 'asesor') || str_contains($filePathLower, 'apoyo eventual')) {
                        $type = 'puesto';
                    } elseif (str_contains($filePathLower, 'proceso') || str_contains($filePathLower, 'sop') || str_contains($filePathLower, 'procedimiento') || str_contains($filePathLower, 'proc ')) {
                        $type = 'proceso';
                    }
                }

                // Determinar icono adecuado
                $icon = $frontmatter['icon'] ?? null;
                if (!$icon) {
                    if ($type === 'puesto') $icon = 'briefcase';
                    elseif ($type === 'proceso') $icon = 'repeat';
                    elseif ($type === 'tarea') $icon = 'check-square';
                    elseif ($type === 'rutina') $icon = 'clock';
                    elseif ($type === 'indicador') $icon = 'trophy';
                    elseif ($type === 'reglas') $icon = 'clipboard-list';
                    elseif ($type === 'formatos') $icon = 'settings';
                    elseif ($type === 'anexo') $icon = 'paperclip';
                    elseif ($type === 'glosario') $icon = 'book-open';
                    elseif ($type === 'organizacion') $icon = 'building-2';
                    else $icon = 'file-text';
                }
            }

                // Upsert document to keep IDs intact
                $document = ObsidianDocument::withTrashed()
                    ->where('tenant_id', $tenantId)
                    ->where('slug', $slug)
                    ->first();

                if ($document) {
                    $document->restore();
                    $document->update([
                        'vault_id' => $vault->id,
                        'filename' => $filename,
                        'title' => $title,
                        'raw_content' => $rawContent,
                        'frontmatter' => $frontmatter,
                        'icon' => $icon,
                        'type' => $type,
                    ]);
                } else {
                    $document = ObsidianDocument::create([
                        'tenant_id' => $tenantId,
                        'vault_id' => $vault->id,
                        'slug' => $slug,
                        'filename' => $filename,
                        'title' => $title,
                        'raw_content' => $rawContent,
                        'frontmatter' => $frontmatter,
                        'icon' => $icon,
                        'type' => $type,
                    ]);
                }

                $parsedDocs[] = $document;
            }

            // Borrado lógico de los documentos que ya no existen en la sincronización actual
            ObsidianDocument::where('tenant_id', $tenantId)
                ->where('vault_id', $vault->id)
                ->whereNotIn('slug', $activeSlugs)
                ->delete();

            // Sincronizar enlaces WikiLinks
            $this->rebuildVaultLinks($tenantId);

            // Guardar fecha de última sincronización
            $vault->update(['last_synced_at' => now()]);

            return $parsedDocs;
        });
    }

    /**
     * Parses WikiLinks and rebuilds links in database.
     */
    private function rebuildVaultLinks($tenantId)
    {
        // Limpiar enlaces previos
        ObsidianLink::where('tenant_id', $tenantId)->delete();

        // Cargar todos los documentos activos del tenant
        $documents = ObsidianDocument::where('tenant_id', $tenantId)->get();
        $titleToDoc = [];
        $slugToDoc = [];

        foreach ($documents as $doc) {
            $titleToDoc[strtolower($doc->title)] = $doc;
            $titleToDoc[strtolower(pathinfo($doc->filename, PATHINFO_FILENAME))] = $doc;
            $slugToDoc[$doc->slug] = $doc;
        }

        foreach ($documents as $doc) {
            $rawContent = $doc->raw_content;
            $parsedContent = $rawContent;

            // Extraer frontmatter para no parsearlo como contenido
            if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)/s', $rawContent, $matches)) {
                $parsedContent = $matches[2];
            }

            // Buscar WikiLinks [[Target]] o [[Target|Label]]
            if (preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', $parsedContent, $linkMatches, PREG_SET_ORDER)) {
                foreach ($linkMatches as $match) {
                    $targetStr = trim($match[1]);
                    $labelStr = isset($match[2]) ? trim($match[2]) : $targetStr;
                    $targetKey = strtolower($targetStr);

                    $targetDoc = $titleToDoc[$targetKey] ?? null;
                    if (!$targetDoc) {
                        // Buscar por slug
                        $targetSlug = Str::slug($targetStr);
                        $targetDoc = $slugToDoc[$targetSlug] ?? null;
                    }

                    if ($targetDoc) {
                        // Crear link en BD
                        ObsidianLink::create([
                            'tenant_id' => $tenantId,
                            'source_document_id' => $doc->id,
                            'target_document_id' => $targetDoc->id,
                            'link_text' => $labelStr
                        ]);

                        // Reemplazar en contenido renderizado por etiqueta interactiva HTML
                        $wikiHtml = '<a href="#" class="wiki-link text-blue-600 dark:text-blue-400 hover:underline font-bold" data-target-slug="' . $targetDoc->slug . '">' . e($labelStr) . '</a>';
                        $rawLinkRegex = '/\\[\\[' . preg_quote($match[1], '/') . (isset($match[2]) ? '\\|' . preg_quote($match[2], '/') : '') . '\\]\\]/';
                        $parsedContent = preg_replace($rawLinkRegex, $wikiHtml, $parsedContent);
                    } else {
                        // WikiLink roto
                        $brokenHtml = '<span class="text-rose-500 line-through" title="Documento no encontrado">' . e($labelStr) . '</span>';
                        $rawLinkRegex = '/\\[\\[' . preg_quote($match[1], '/') . (isset($match[2]) ? '\\|' . preg_quote($match[2], '/') : '') . '\\]\\]/';
                        $parsedContent = preg_replace($rawLinkRegex, $brokenHtml, $parsedContent);
                    }
                }
            }

            // Convertir markdown básico a HTML para rendering visual fluido
            $htmlContent = $this->markdownToHtml($parsedContent);

            $doc->update(['content' => $htmlContent]);
        }
    }

    /**
     * Simple parser to convert basic markdown constructs to clean HTML.
     */
    private function markdownToHtml($markdown)
    {
        // Escape standard HTML first to prevent injection
        $html = e($markdown);

        // Restore our wiki links <a href="#" class="wiki-link ...">...</a> or broken spans
        $html = htmlspecialchars_decode($html);

        // Headings
        $html = preg_replace('/^######\s+(.*)$/m', '<h6 class="text-xs font-bold uppercase tracking-wider text-slate-400 mt-4 mb-2">$1</h6>', $html);
        $html = preg_replace('/^#####\s+(.*)$/m', '<h5 class="text-sm font-bold text-slate-800 mt-4 mb-2">$1</h5>', $html);
        $html = preg_replace('/^####\s+(.*)$/m', '<h4 class="text-base font-black text-slate-800 mt-5 mb-2">$1</h4>', $html);
        $html = preg_replace('/^###\s+(.*)$/m', '<h3 class="text-lg font-black text-slate-900 border-b border-slate-100 pb-1 mt-6 mb-3">$1</h3>', $html);
        $html = preg_replace('/^##\s+(.*)$/m', '<h2 class="text-xl font-black text-slate-900 border-b border-slate-200 pb-1.5 mt-7 mb-4">$1</h2>', $html);
        $html = preg_replace('/^#\s+(.*)$/m', '<h1 class="text-2xl sm:text-3xl font-black text-slate-900 mt-8 mb-4">$1</h1>', $html);

        // Checkboxes (Tasks)
        $html = preg_replace('/^-\s+\[\s*\]\s+(.*)$/m', '<div class="flex items-center gap-2 my-1"><input type="checkbox" disabled class="rounded border-slate-350 text-blue-600 focus:ring-blue-500 w-4.5 h-4.5" /> <span class="text-slate-700 text-sm">$1</span></div>', $html);
        $html = preg_replace('/^-\s+\[x\]\s+(.*)$/m', '<div class="flex items-center gap-2 my-1"><input type="checkbox" checked disabled class="rounded border-slate-350 text-blue-600 focus:ring-blue-500 w-4.5 h-4.5" /> <span class="text-slate-500 line-through text-sm">$1</span></div>', $html);

        // Bullet lists
        $html = preg_replace('/^-\s+(?!<div)(.*)$/m', '<li class="ml-4 list-disc text-slate-750 text-sm my-1">$1</li>', $html);
        $html = preg_replace('/^\*\s+(.*)$/m', '<li class="ml-4 list-disc text-slate-750 text-sm my-1">$1</li>', $html);

        // Blockquotes
        $html = preg_replace('/^>\s+(.*)$/m', '<blockquote class="border-l-4 border-blue-500 bg-blue-50/50 px-4 py-2 rounded-r-xl my-4 text-slate-700 text-sm italic">$1</blockquote>', $html);

        // Bold & Italics
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong class="font-bold text-slate-900">$1</strong>', $html);
        $html = preg_replace('/\*(.*?)\*/', '<em class="italic text-slate-800">$1</em>', $html);

        // Code blocks & inline code
        $html = preg_replace('/`([^`]+)`/', '<code class="bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded text-xs font-mono text-pink-600">$1</code>', $html);

        // Paragraph linebreaks helper (convert double newlines to paragraph blocks, but avoid wrapping HTML blocks)
        $lines = explode("\n", $html);
        $inList = false;
        $inBlock = false;
        $formattedLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                if ($inList) {
                    $formattedLines[] = '</ul>';
                    $inList = false;
                }
                continue;
            }

            if (str_starts_with($trimmed, '<li') && !$inList) {
                $formattedLines[] = '<ul class="space-y-1 my-3">';
                $inList = true;
            }

            if (!str_starts_with($trimmed, '<h') && !str_starts_with($trimmed, '<ul') && !str_starts_with($trimmed, '<li') && !str_starts_with($trimmed, '</ul') && !str_starts_with($trimmed, '<blockquote') && !str_starts_with($trimmed, '<div') && !$inList) {
                $line = '<p class="text-slate-750 text-sm leading-relaxed my-3">' . $line . '</p>';
            }

            $formattedLines[] = $line;
        }

        if ($inList) {
            $formattedLines[] = '</ul>';
        }

        return implode("\n", $formattedLines);
    }

    /**
     * Fetch document index/tree for the vault.
     */
    public function getDocuments()
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $documents = ObsidianDocument::where('tenant_id', $tenantId)
            ->select('id', 'title', 'slug', 'icon', 'type')
            ->orderBy('title', 'asc')
            ->get()
            ->groupBy('type');

        return response()->json([
            'vault_name' => $this->getOrCreateVault()->name,
            'documents' => $documents
        ]);
    }

    /**
     * Fetch specific document by slug, with links and backlinks.
     */
    public function getDocument($slug)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $doc = ObsidianDocument::where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->firstOrFail();

        // Get related documents (outward links)
        $links = ObsidianLink::where('tenant_id', $tenantId)
            ->where('source_document_id', $doc->id)
            ->with('targetDocument:id,title,slug,icon,type')
            ->get()
            ->map(function ($l) {
                return $l->targetDocument;
            })->filter()->values();

        // Get backlinks (inward links)
        $backlinks = ObsidianLink::where('tenant_id', $tenantId)
            ->where('target_document_id', $doc->id)
            ->with('sourceDocument:id,title,slug,icon,type')
            ->get()
            ->map(function ($l) {
                return $l->sourceDocument;
            })->filter()->values();

        return response()->json([
            'document' => $doc,
            'links' => $links,
            'backlinks' => $backlinks
        ]);
    }

    /**
     * Submit change suggestion (Employee view).
     */
    public function suggestChange(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $userId = auth()->id();

        $request->validate([
            'document_id' => 'required|integer|exists:obsidian_documents,id',
            'proposed_content' => 'required|string',
            'comment' => 'nullable|string',
        ]);

        $doc = ObsidianDocument::where('tenant_id', $tenantId)->findOrFail($request->document_id);

        $suggestion = ObsidianSuggestion::create([
            'tenant_id' => $tenantId,
            'document_id' => $doc->id,
            'user_id' => $userId,
            'author_name' => auth()->user()->name ?? 'Colaborador',
            'original_content' => $doc->raw_content,
            'proposed_content' => $request->proposed_content,
            'comment' => $request->comment,
            'status' => 'pending'
        ]);

        return response()->json(['message' => 'Sugerencia enviada correctamente para revisión administrativa.', 'suggestion' => $suggestion]);
    }

    /**
     * Fetch list of suggestions (Admin view).
     */
    public function getSuggestions()
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $suggestions = ObsidianSuggestion::where('tenant_id', $tenantId)
            ->with(['document:id,title,slug,icon', 'user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($suggestions);
    }

    /**
     * Approve suggested changes (Admin action).
     */
    public function approveSuggestion(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $reviewerId = auth()->id();

        $suggestion = ObsidianSuggestion::where('tenant_id', $tenantId)->findOrFail($id);

        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Esta propuesta ya ha sido procesada previamente.'], 400);
        }

        DB::transaction(function () use ($suggestion, $tenantId, $reviewerId, $request) {
            $doc = ObsidianDocument::where('tenant_id', $tenantId)->findOrFail($suggestion->document_id);

            // Actualizar documento con la propuesta
            $doc->update([
                'raw_content' => $suggestion->proposed_content
            ]);

            // Re-procesar WikiLinks del baúl completo por si cambió enlaces
            $this->rebuildVaultLinks($tenantId);

            // Actualizar sugerencia
            $suggestion->update([
                'status' => 'approved',
                'reviewer_id' => $reviewerId,
                'reviewed_at' => now(),
                'review_comment' => $request->review_comment ?? 'Aprobado por el Administrador.'
            ]);
        });

        return response()->json(['message' => 'Propuesta aprobada con éxito. El documento ha sido actualizado en tiempo real.']);
    }

    /**
     * Reject suggested changes (Admin action).
     */
    public function rejectSuggestion(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $reviewerId = auth()->id();

        $suggestion = ObsidianSuggestion::where('tenant_id', $tenantId)->findOrFail($id);

        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Esta propuesta ya ha sido procesada previamente.'], 400);
        }

        $suggestion->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewed_at' => now(),
            'review_comment' => $request->review_comment ?? 'Rechazado por el Administrador.'
        ]);

        return response()->json(['message' => 'Propuesta rechazada con éxito. El documento original permanece inalterado.']);
    }

    /**
     * Edit document directly (Admin action).
     */
    public function editDocument(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $request->validate([
            'id' => 'required|integer|exists:obsidian_documents,id',
            'title' => 'required|string|max:255',
            'raw_content' => 'required|string',
            'type' => 'required|string',
            'icon' => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $tenantId) {
            $doc = ObsidianDocument::where('tenant_id', $tenantId)->findOrFail($request->id);
            $doc->update([
                'title' => $request->title,
                'raw_content' => $request->raw_content,
                'type' => $request->type,
                'icon' => $request->icon ?? $doc->icon,
            ]);

            $this->rebuildVaultLinks($tenantId);
        });

        return response()->json(['message' => 'Documento actualizado con éxito.']);
    }

    /**
     * Public read-only endpoint (Get public documentation).
     */
    public function getPublicDocument(Request $request, $tenantSlug, $docSlug = null)
    {
        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $tenantId = $tenant->id;

        $vault = ObsidianVault::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        if (!$vault) {
            return response()->json(['message' => 'No se ha configurado estructura organizacional en esta empresa.'], 404);
        }

        // Resolving the authenticated ObsidianUser token manually
        $user = null;
        $token = $request->bearerToken() ?: $request->token;
        if ($token) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel && $tokenModel->tokenable instanceof \App\Models\ObsidianUser) {
                $user = $tokenModel->tokenable;
            }
        }

        // Cargar índice base
        $docsQuery = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('vault_id', $vault->id);

        $filteredDocs = collect();

        if (!$user) {
            // Si no está logueado, no se muestra ningún documento en el índice público
            $index = collect();
        } else if ($user->role === 'colaborador') {
            // Filtrar documentos para colaboradores normales basados en su puesto
            $jobRole = $user->jobRole;
            $keywords = [];
            if ($jobRole) {
                $roleName = mb_strtolower(Str::ascii($jobRole->name), 'UTF-8');
                $words = preg_split('/[\s\-_,]+/', $roleName);
                $stopWords = ['de', 'la', 'el', 'en', 'y', 'con', 'para', 'un', 'una', 'del', 'al', 'los', 'las', 'a', 'o', 'u', 'e', 'apoyo', 'eventual', 'integral'];
                $keywords = array_filter($words, function ($w) use ($stopWords) {
                    return strlen($w) > 2 && !in_array($w, $stopWords);
                });
            }

            $allDocs = $docsQuery->get();
            $filteredDocs = $allDocs->filter(function ($doc) use ($keywords) {
                $docType = $doc->type;
                
                // 1. Tipos siempre públicos (Organización, Glosario, Notas generales)
                if (in_array($docType, ['organizacion', 'glosario', 'nota'])) {
                    return true;
                }

                $titleLower = mb_strtolower(Str::ascii($doc->title), 'UTF-8');

                // 2. Documentos generales de RRHH / Administrativos
                $generalKeywords = ['contrato', 'convenio', 'responsiva', 'expediente', 'ingreso', 'reglamento', 'taller', 'sucursal', 'soda', 'talent360', 'academia', 'fundador', 'historia'];
                foreach ($generalKeywords as $gk) {
                    if (str_contains($titleLower, $gk)) {
                        return true;
                    }
                }

                // 3. Coincidencia por palabra clave del puesto
                foreach ($keywords as $kw) {
                    if (str_contains($titleLower, $kw)) {
                        return true;
                    }
                }

                return false;
            });

            $index = $filteredDocs->sortBy('title')->groupBy('type');
        } else {
            // Admin ve todo
            $filteredDocs = $docsQuery->get();
            $index = $filteredDocs->sortBy('title')->groupBy('type');
        }

        // Si se pide un documento específico, validar que exista y tenga permiso
        $doc = null;
        if ($docSlug && $user) {
            $doc = ObsidianDocument::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('vault_id', $vault->id)
                ->where('slug', $docSlug)
                ->first();
            
            if ($doc && $user->role === 'colaborador') {
                $isAuthorized = $filteredDocs->contains('id', $doc->id);
                if (!$isAuthorized) {
                    return response()->json(['error' => 'No tienes autorización para visualizar este documento según tu puesto de trabajo.'], 403);
                }
            }
        }

        // Si no se pide un documento o el solicitado no existe, cargar el primero autorizado
        if (!$doc && $user && $filteredDocs->count() > 0) {
            $firstCat = ['organizacion', 'puesto', 'tarea', 'rutina', 'indicador', 'reglas', 'formatos', 'anexo', 'glosario', 'nota'];
            foreach ($firstCat as $cat) {
                if (isset($index[$cat]) && count($index[$cat]) > 0) {
                    $doc = $index[$cat]->first();
                    break;
                }
            }
            if (!$doc) {
                $doc = $filteredDocs->first();
            }
        }

        if (!$doc) {
            return response()->json([
                'tenant' => [
                    'name' => $tenant->name,
                    'logo_url' => $tenant->logo_url,
                    'brand_color' => $tenant->brand_color ?: '#3b82f6'
                ],
                'vault_name' => $vault->name,
                'hide_oracle_button' => (bool) $vault->hide_oracle_button,
                'index' => $index,
                'document' => null
            ]);
        }

        // Cargar links y backlinks para el público
        $links = ObsidianLink::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_document_id', $doc->id)
            ->with(['targetDocument' => function ($q) {
                $q->withoutGlobalScopes()->select('id', 'title', 'slug', 'icon', 'type');
            }])
            ->get()
            ->map(function ($l) {
                return $l->targetDocument;
            })->filter()->values();

        $backlinks = ObsidianLink::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('target_document_id', $doc->id)
            ->with(['sourceDocument' => function ($q) {
                $q->withoutGlobalScopes()->select('id', 'title', 'slug', 'icon', 'type');
            }])
            ->get()
            ->map(function ($l) {
                return $l->sourceDocument;
            })->filter()->values();

        return response()->json([
            'tenant' => [
                'name' => $tenant->name,
                'logo_url' => $tenant->logo_url,
                'brand_color' => $tenant->brand_color ?: '#3b82f6'
            ],
            'vault_name' => $vault->name,
            'hide_oracle_button' => (bool) $vault->hide_oracle_button,
            'index' => $index,
            'document' => $doc,
            'links' => $links,
            'backlinks' => $backlinks
        ]);
    }

    /**
     * AI Copilot chatbot endpoint for the organizational vault.
     */
    public function copilot(Request $request, $tenantSlug)
    {
        $request->validate([
            'question' => 'required|string',
        ]);

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $documents = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->select('title', 'type', 'raw_content')
            ->get();

        $contextText = "";
        foreach ($documents as $doc) {
            $contextText .= "Documento: " . $doc->title . " (Tipo: " . $doc->type . ")\n";
            $contextText .= "Contenido:\n" . $doc->raw_content . "\n";
            $contextText .= "--------------------------------------\n";
        }

        $systemInstruction = "Eres la IA Asistente de 'La Receta Secreta', el manual de operaciones y estructura organizacional de la empresa " . $tenant->name . ".
Tu objetivo es responder de forma clara, amigable y muy concisa las preguntas de los empleados basándote ÚNICAMENTE en el contenido de los documentos provistos abajo.
Si la respuesta no se encuentra en la documentación, responde amablemente indicando que ese dato no está registrado en el manual operativo.
No inventes datos de salarios, reglas o puestos si no están en el contexto.

DOCUMENTACIÓN COMPLETA DE LA EMPRESA:
" . $contextText;

        $geminiKey = env('GEMINI_API_KEY');
        if (!$geminiKey) {
            return response()->json([
                'answer' => "Modo Demo: Hola, soy el Asistente de La Receta Secreta. Para darte respuestas reales con IA, por favor configura la variable GEMINI_API_KEY en tu archivo .env. Preguntaste por: \"" . $request->question . "\""
            ]);
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemInstruction . "\n\nPregunta del colaborador: " . $request->question]
                        ]
                    ]
                ]
            ]);

            if ($response->failed()) {
                return response()->json(['error' => 'Error al conectar con la IA.'], 500);
            }

            $answer = $response->json('candidates.0.content.parts.0.text') ?? 'No tengo respuesta en este momento.';
            return response()->json(['answer' => trim($answer)]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if a passcode is valid.
     */
    private function validatePasscode($passcode, $requireAdmin = false)
    {
        $adminPasscodes = ['Guru28'];
        $employeePasscodes = ['Chivas2017', '251302', '55'];
        $allPasscodes = array_merge($adminPasscodes, $employeePasscodes);

        if ($requireAdmin) {
            return in_array($passcode, $adminPasscodes);
        }
        return in_array($passcode, $allPasscodes);
    }

    /**
     * Validate passcode endpoint for public page.
     */
    public function validatePublicPasscode(Request $request)
    {
        $request->validate([
            'passcode' => 'required|string'
        ]);

        $passcode = $request->passcode;
        $isValid = $this->validatePasscode($passcode);

        if (!$isValid) {
            return response()->json(['error' => 'Contraseña incorrecta.'], 403);
        }

        $role = in_array($passcode, ['Guru28']) ? 'auditor' : 'colaborador';

        return response()->json([
            'valid' => true,
            'role' => $role
        ]);
    }

    private function resolvePublicUser(Request $request)
    {
        $token = $request->bearerToken() ?: $request->token ?: $request->passcode;
        if ($token) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel && $tokenModel->tokenable instanceof \App\Models\ObsidianUser) {
                return $tokenModel->tokenable;
            }
        }
        return null;
    }

    /**
     * Get suggestions on public page (Admin only).
     */
    public function getPublicSuggestions(Request $request, $tenantSlug)
    {
        $user = $this->resolvePublicUser($request);
        if (!$user || $user->role !== 'admin') {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $suggestions = ObsidianSuggestion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with(['document:id,title,slug,icon'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($suggestions);
    }

    /**
     * Approve suggestion on public page (Admin only).
     */
    public function approvePublicSuggestion(Request $request, $tenantSlug, $id)
    {
        $user = $this->resolvePublicUser($request);
        if (!$user || $user->role !== 'admin') {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $suggestion = ObsidianSuggestion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Esta propuesta ya ha sido procesada previamente.'], 400);
        }

        DB::transaction(function () use ($suggestion, $tenant, $request) {
            $doc = ObsidianDocument::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->findOrFail($suggestion->document_id);

            // Actualizar documento con la propuesta
            $doc->update([
                'raw_content' => $suggestion->proposed_content
            ]);

            // Re-procesar WikiLinks
            $this->rebuildVaultLinks($tenant->id);

            // Actualizar sugerencia
            $suggestion->update([
                'status' => 'approved',
                'reviewer_id' => null, // null ya que es público
                'reviewed_at' => now(),
                'review_comment' => $request->review_comment ?? 'Aprobado vía Oráculo Público.'
            ]);
        });

        return response()->json(['message' => 'Propuesta aprobada con éxito. El documento ha sido actualizado en tiempo real.']);
    }

    /**
     * Reject suggestion on public page (Admin only).
     */
    public function rejectPublicSuggestion(Request $request, $tenantSlug, $id)
    {
        $user = $this->resolvePublicUser($request);
        if (!$user || $user->role !== 'admin') {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $suggestion = ObsidianSuggestion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Esta propuesta ya ha sido procesada previamente.'], 400);
        }

        $suggestion->update([
            'status' => 'rejected',
            'reviewer_id' => null,
            'reviewed_at' => now(),
            'review_comment' => $request->review_comment ?? 'Rechazado vía Oráculo Público.'
        ]);

        return response()->json(['message' => 'Propuesta rechazada con éxito. El documento original permanece inalterado.']);
    }

    /**
     * Submit suggestion on public page (Any valid logged in manual user).
     */
    public function createPublicSuggestion(Request $request, $tenantSlug)
    {
        $request->validate([
            'document_id' => 'required|integer',
            'proposed_content' => 'required|string',
            'comment' => 'required|string',
            'user_name' => 'required|string|max:255'
        ]);

        $user = $this->resolvePublicUser($request);
        if (!$user) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        // Validar documento
        $doc = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($request->document_id);

        // Crear sugerencia
        $suggestion = ObsidianSuggestion::create([
            'tenant_id' => $tenant->id,
            'document_id' => $doc->id,
            'user_id' => $user->id,
            'author_name' => $request->user_name,
            'original_content' => $doc->raw_content,
            'proposed_content' => $request->proposed_content,
            'comment' => $request->comment,
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Propuesta de mejora enviada al Oráculo de la empresa con éxito.',
            'suggestion' => $suggestion
        ]);
    }

    /**
     * AI Scribe endpoint to generate full employment/onboarding documentation packages.
     */
    public function scribe(Request $request, $tenantSlug)
    {
        $request->validate([
            'candidate_name' => 'required|string|max:255',
            'job_role_slug' => 'required|string|max:255',
            'documents' => 'required|array',
        ]);

        $user = $this->resolvePublicUser($request);
        if (!$user) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        // 1. Obtener documento del puesto
        $roleDoc = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $request->job_role_slug)
            ->firstOrFail();

        // 2. Intentar buscar el reglamento interno en el baúl
        $rulesDoc = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(function($q) {
                $q->where('title', 'like', '%reglamento%')
                  ->orWhere('title', 'like', '%reglas%')
                  ->orWhere('title', 'like', '%politicas%');
            })->first();

        // 3. Buscar procesos de apoyo
        $processes = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'proceso')
            ->select('title', 'raw_content')
            ->limit(5)
            ->get();

        $processesText = "";
        foreach ($processes as $p) {
            $processesText .= "- " . $p->title . ": " . Str::limit($p->raw_content, 300) . "\n";
        }

        $roleText = "Puesto: " . $roleDoc->title . "\nContenido del manual:\n" . $roleDoc->raw_content;
        $rulesText = $rulesDoc ? "Reglamento Interno:\n" . $rulesDoc->raw_content : "No hay reglamento interno explícito, usa reglas de conducta generales de la repostería y comercio.";

        $docsList = $request->documents;
        $instructions = "";
        
        if (in_array('contract', $docsList)) {
            $instructions .= "1. CONTRATO LABORAL: Redacta un contrato individual de trabajo formal bajo la ley mexicana (LFT), vinculando al patrón ({$tenant->name}) y al trabajador ({$request->candidate_name}) para el puesto de {$roleDoc->title}. Detalla salario diario, jornada de trabajo, descansos y cláusula de confidencialidad.\n\n";
        }
        if (in_array('tasks', $docsList)) {
            $instructions .= "2. OBLIGACIONES Y TAREAS (SOP): Un desglose claro y directo de las funciones, listas de tareas diarias y rutinas operativas del puesto ({$roleDoc->title}) basado en la información del manual.\n\n";
        }
        if (in_array('rules', $docsList)) {
            $instructions .= "3. REGLAMENTO INTERNO Y SANCIONES: Las normas de conducta del establecimiento, faltas comunes (llegadas tarde, uniformes) y las medidas disciplinarias según la LFT mexicana.\n\n";
        }
        if (in_array('responsive', $docsList)) {
            $instructions .= "4. CARTA RESPONSIVA DE EQUIPO: Un formato formal donde {$request->candidate_name} acepta la responsabilidad de resguardar el equipo, herramientas o utensilios asignados en su puesto de {$roleDoc->title}.\n\n";
        }

        $systemInstruction = "Eres el Escribano Mayor de la empresa {$tenant->name}. Tu deber es redactar formalmente los documentos de contratación y onboarding solicitados para el nuevo colaborador: {$request->candidate_name}, en el cargo de {$roleDoc->title}.

INFORMACIÓN DE REFERENCIA DEL PUESTO:
{$roleText}

REGLAMENTO Y POLÍTICAS DE LA EMPRESA:
{$rulesText}

PROCESOS DE APOYO:
{$processesText}

INSTRUCCIONES DE REDACCIÓN:
Genera un único bloque de texto formateado en Markdown limpio y sumamente profesional.
Separa cada uno de los documentos solicitados con un separador visual de página en Markdown: '---'.
Rellena todos los campos vacíos con datos hipotéticos lógicos y formales basados en el manual. El lenguaje debe ser estrictamente en español formal, legal y corporativo mexicano.
Usa etiquetas legibles. Hoy es " . date('d/m/Y') . ".";

        $geminiKey = env('GEMINI_API_KEY');
        if (!$geminiKey) {
            return response()->json([
                'html' => "<h3>Modo Demo</h3><p>Para generar contratos con IA, configura la variable GEMINI_API_KEY en tu archivo .env. Datos del colaborador: {$request->candidate_name} como {$roleDoc->title}.</p>"
            ]);
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $systemInstruction . "\n\nRedacta ahora los pergaminos de contratación:\n" . $instructions]
                        ]
                    ]
                ]
            ]);

            if ($response->failed()) {
                return response()->json(['error' => 'Error al conectar con el Escribano AI.'], 500);
            }

            $markdown = $response->json('candidates.0.content.parts.0.text') ?? 'No se pudo generar la documentación.';
            
            // Convertir markdown a HTML
            $html = Str::markdown($markdown);

            return response()->json([
                'markdown' => $markdown,
                'html' => $html
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Public login for manual collaborators (isolated user system).
     */
    public function publicLogin(Request $request, $tenantSlug)
    {
        $request->validate([
            'email' => 'required|string',
            'password' => 'required|string'
        ]);

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $user = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Usuario o contraseña incorrectos.'], 401);
        }

        $token = $user->createToken('vault-user-token')->plainTextToken;

        return response()->json([
            'valid' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'job_role_id' => $user->job_role_id,
                'job_role_name' => $user->jobRole?->name ?? 'N/A',
            ],
            'token' => $token
        ]);
    }

    /**
     * Record reading progress for an isolated user.
     */
    public function recordReadProgress(Request $request, $tenantSlug)
    {
        $request->validate([
            'document_id' => 'required|integer'
        ]);

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $user = null;
        $token = $request->bearerToken() ?: $request->token;
        if ($token) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel && $tokenModel->tokenable instanceof \App\Models\ObsidianUser) {
                $user = $tokenModel->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        $progress = ObsidianReadProgress::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'document_id' => $request->document_id
        ]);

        return response()->json([
            'message' => 'Progreso guardado correctamente.',
            'progress' => $progress
        ]);
    }

    /**
     * Admin: List isolated manual users.
     */
    public function listUsers()
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $users = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('jobRole')
            ->get();

        return response()->json($users);
    }

    /**
     * Admin: Create an isolated manual user.
     */
    public function createUser(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'password' => 'required|string|min:4',
            'job_role_id' => 'nullable|integer',
            'role' => 'required|string|in:admin,colaborador'
        ]);

        $exists = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $request->email)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'El correo/usuario ya se encuentra registrado.'], 400);
        }

        $user = ObsidianUser::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'job_role_id' => $request->job_role_id,
            'role' => $request->role
        ]);

        return response()->json(['message' => 'Usuario del manual creado con éxito.', 'user' => $user]);
    }

    /**
     * Admin: Update an isolated manual user.
     */
    public function updateUser(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $user = ObsidianUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'password' => 'nullable|string|min:4',
            'job_role_id' => 'nullable|integer',
            'role' => 'required|string|in:admin,colaborador'
        ]);

        $exists = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $request->email)
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'El correo/usuario ya se encuentra en uso por otra cuenta.'], 400);
        }

        $user->name = $request->name;
        $user->email = $request->email;
        $user->job_role_id = $request->job_role_id;
        $user->role = $request->role;

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();

        return response()->json(['message' => 'Usuario del manual actualizado con éxito.', 'user' => $user]);
    }

    /**
     * Admin: Delete an isolated manual user.
     */
    public function deleteUser($id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $user = ObsidianUser::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'Usuario del manual eliminado con éxito.']);
    }

    /**
     * Admin: Query progress summary for manual users.
     */
    public function progressSummary()
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $vault = ObsidianVault::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        if (!$vault) {
            return response()->json([]);
        }

        // Obtener total de documentos en el baúl
        $totalDocs = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('vault_id', $vault->id)
            ->count();

        // Obtener todos los usuarios del manual y calcular su progreso
        $users = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['jobRole', 'readProgress'])
            ->get();

        $summary = $users->map(function ($u) use ($totalDocs) {
            $readCount = $u->readProgress->count();
            $percentage = $totalDocs > 0 ? round(($readCount / $totalDocs) * 100, 1) : 0;
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'job_role' => $u->jobRole?->name ?? 'Administración',
                'read_count' => $readCount,
                'total_docs' => $totalDocs,
                'percentage' => $percentage
            ];
        });

        return response()->json($summary);
    }
}
