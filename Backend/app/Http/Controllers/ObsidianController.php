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
use Illuminate\Support\Str;

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
        ]);

        $vault->update([
            'name' => $request->name,
            'local_path' => $request->local_path,
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
                }

                // Extracción de metadatos básicos
                $title = $frontmatter['title'] ?? pathinfo($filename, PATHINFO_FILENAME);
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

                // Determinar tipo
                $type = $frontmatter['type'] ?? 'nota';
                $type = strtolower($type);
                $filePathLower = strtolower($file->getRelativePathname());
                if ($type === 'nota') {
                    if (str_contains($filePathLower, 'puesto') || str_contains($filePathLower, 'role')) {
                        $type = 'puesto';
                    } elseif (str_contains($filePathLower, 'proceso') || str_contains($filePathLower, 'sop')) {
                        $type = 'proceso';
                    } elseif (str_contains($filePathLower, 'tarea') || str_contains($filePathLower, 'checklist')) {
                        $type = 'tarea';
                    }
                }

                // Determinar icono
                $icon = $frontmatter['icon'] ?? null;
                if (!$icon) {
                    if ($type === 'puesto') $icon = 'briefcase';
                    elseif ($type === 'proceso') $icon = 'repeat';
                    elseif ($type === 'tarea') $icon = 'check-square';
                    else $icon = 'file-text';
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
    public function getPublicDocument($tenantSlug, $docSlug = null)
    {
        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $tenantId = $tenant->id;

        $vault = ObsidianVault::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        if (!$vault) {
            return response()->json(['message' => 'No se ha configurado estructura organizacional en esta empresa.'], 404);
        }

        // Cargar índice
        $index = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('vault_id', $vault->id)
            ->select('id', 'title', 'slug', 'icon', 'type')
            ->orderBy('title', 'asc')
            ->get()
            ->groupBy('type');

        // Si no se pide un documento específico, cargar el "index" o el primero disponible
        if (!$docSlug) {
            // Intentar cargar "index", "inicio", "readme", o el primero del índice
            $doc = ObsidianDocument::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('vault_id', $vault->id)
                ->where(function ($q) {
                    $q->where('slug', 'index')
                      ->orWhere('slug', 'inicio')
                      ->orWhere('slug', 'readme')
                      ->orWhere('slug', 'bienvenida');
                })->first();

            if (!$doc) {
                $doc = ObsidianDocument::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('vault_id', $vault->id)
                    ->first();
            }
        } else {
            $doc = ObsidianDocument::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('vault_id', $vault->id)
                ->where('slug', $docSlug)
                ->firstOrFail();
        }

        if (!$doc) {
            return response()->json([
                'tenant' => [
                    'name' => $tenant->name,
                    'logo_url' => $tenant->logo_url,
                    'brand_color' => $tenant->brand_color ?: '#3b82f6'
                ],
                'vault_name' => $vault->name,
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
            'index' => $index,
            'document' => $doc,
            'links' => $links,
            'backlinks' => $backlinks
        ]);
    }
}
