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
use App\Models\ObsidianExam;
use App\Models\ObsidianExamQuestion;
use App\Models\ObsidianExamAttempt;
use App\Models\JobRole;

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
            'gemini_api_key' => 'nullable|string|max:255',
        ]);

        $vault->update([
            'name' => $request->name,
            'local_path' => $request->local_path,
            'hide_oracle_button' => (bool) $request->hide_oracle_button,
            'gemini_api_key' => $request->gemini_api_key,
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
     * Purge all documents and links in the current vault.
     */
    public function purgeVault(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $vault = $this->getOrCreateVault();

        \DB::transaction(function () use ($tenantId, $vault) {
            // Delete all documents in this vault
            \App\Models\ObsidianDocument::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('vault_id', $vault->id)
                ->delete();

            // Clear links associated with the tenant
            \DB::table('obsidian_links')
                ->where('tenant_id', $tenantId)
                ->delete();
        });

        return response()->json(['message' => 'Baúl depurado/vaciado con éxito.']);
    }

    /**
     * Rebuild links cache.
     */
    public function rebuildCache(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $this->rebuildVaultLinks($tenantId);
        return response()->json(['message' => 'Índice de enlaces reconstruido con éxito.']);
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

        $vault = ObsidianVault::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->sortByDesc(function ($v) {
                return ObsidianDocument::withoutGlobalScopes()->where('vault_id', $v->id)->count();
            })
            ->first();
        if (!$vault) {
            return response()->json(['message' => 'No se ha configurado estructura organizacional en esta empresa.'], 404);
        }

        // Resolving the authenticated ObsidianUser token manually
        $user = null;
        $token = $request->bearerToken() ?: $request->token;
        if ($token) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($tokenModel && ($tokenModel->tokenable_type === 'App\Models\ObsidianUser' || is_a($tokenModel->tokenable_type, \App\Models\ObsidianUser::class, true))) {
                $user = \App\Models\ObsidianUser::withoutGlobalScopes()
                    ->where('id', $tokenModel->tokenable_id)
                    ->first();
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
        } else {
            $isRoleAdmin = ($user->role === 'administrador' || $user->role === 'admin' || $user->role === 'supervisor');
            $roleNameLower = mb_strtolower($user->jobRole?->name ?? '', 'UTF-8');
            $isAdminTitle = str_contains($roleNameLower, 'administrador') || str_contains($roleNameLower, 'gerente') || str_contains($roleNameLower, 'director');
            $isUserAdmin = $isRoleAdmin || $isAdminTitle;

            if ($isUserAdmin) {
                // Admin sees all documents
                $filteredDocs = $docsQuery->orderBy('position', 'asc')->orderBy('title', 'asc')->get();
            } else {
                // Colaborador: filter documents by job role assignment matrix
                if ($user->job_role_id) {
                    $assignedDocIds = DB::table('obsidian_document_job_role')
                        ->where('tenant_id', $tenantId)
                        ->where('job_role_id', $user->job_role_id)
                        ->pluck('document_id')
                        ->toArray();

                    $filteredDocs = $docsQuery->whereIn('id', $assignedDocIds)
                        ->orderBy('position', 'asc')
                        ->orderBy('title', 'asc')
                        ->get();
                } else {
                    $filteredDocs = collect();
                }
            }

            $index = $filteredDocs->groupBy('type');
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

        // Cargar puestos de trabajo disponibles para que el usuario pueda seleccionarlo al registrarse
        $jobRoles = DB::table('job_roles')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        // Cargar los slugs de los documentos que este usuario específico ya leyó
        $readDocSlugs = [];
        if ($user) {
            $readDocSlugs = ObsidianReadProgress::withoutGlobalScopes()
                ->where('obsidian_read_progress.tenant_id', $tenantId)
                ->where('obsidian_read_progress.user_id', $user->id)
                ->join('obsidian_documents', 'obsidian_read_progress.document_id', '=', 'obsidian_documents.id')
                ->pluck('obsidian_documents.slug')
                ->toArray();
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
                'document' => null,
                'job_roles' => $jobRoles,
                'read_doc_slugs' => $readDocSlugs
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
            'backlinks' => $backlinks,
            'job_roles' => $jobRoles,
            'read_doc_slugs' => $readDocSlugs
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

        $vault = ObsidianVault::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $geminiKey = $vault?->gemini_api_key ?? env('GEMINI_API_KEY');
        if (!$geminiKey) {
            return response()->json([
                'answer' => "Modo Demo: Hola, soy el Asistente de La Receta Secreta. Para darte respuestas reales con IA, por favor configura la clave de API de Gemini en la configuración del manual. Preguntaste por: \"" . $request->question . "\""
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
            if ($tokenModel && ($tokenModel->tokenable_type === 'App\Models\ObsidianUser' || is_a($tokenModel->tokenable_type, \App\Models\ObsidianUser::class, true))) {
                return \App\Models\ObsidianUser::withoutGlobalScopes()
                    ->where('id', $tokenModel->tokenable_id)
                    ->first();
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

        $vault = ObsidianVault::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $geminiKey = $vault?->gemini_api_key ?? env('GEMINI_API_KEY');
        if (!$geminiKey) {
            return response()->json([
                'html' => "<h3>Modo Demo</h3><p>Para generar contratos con IA, por favor configura la clave de API de Gemini en la configuración del manual. Datos del colaborador: {$request->candidate_name} como {$roleDoc->title}.</p>"
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

        if (!$user) {
            // Check if they are a platform user in the main users table
            $mainUser = \App\Models\User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('email', $request->email)
                ->first();

            if ($mainUser && Hash::check($request->password, $mainUser->password)) {
                // Determine appropriate Obsidian role based on platform role
                $obsidianRole = 'colaborador';
                if ($mainUser->role === 'admin' || $mainUser->role === 'platform_admin') {
                    $obsidianRole = 'admin';
                } elseif ($mainUser->role === 'supervisor') {
                    $obsidianRole = 'supervisor';
                }

                // Auto-create ObsidianUser to sync credentials
                $user = ObsidianUser::create([
                    'tenant_id' => $tenant->id,
                    'name' => $mainUser->name,
                    'email' => $mainUser->email,
                    'password' => $mainUser->password, // keeps bcrypt hash
                    'role' => $obsidianRole,
                    'job_role_id' => $mainUser->job_role_id,
                ]);
            }
        } else {
            // Check password
            if (!Hash::check($request->password, $user->password)) {
                // Fallback: check if the main user has updated their password
                $mainUser = \App\Models\User::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('email', $request->email)
                    ->first();

                if ($mainUser && Hash::check($request->password, $mainUser->password)) {
                    // Sync password to ObsidianUser
                    $user->password = $mainUser->password;
                    $user->save();
                } else {
                    return response()->json(['error' => 'Usuario o contraseña incorrectos.'], 401);
                }
            }
        }

        if (!$user) {
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
            if ($tokenModel && ($tokenModel->tokenable_type === 'App\Models\ObsidianUser' || is_a($tokenModel->tokenable_type, \App\Models\ObsidianUser::class, true))) {
                $user = \App\Models\ObsidianUser::withoutGlobalScopes()
                    ->where('id', $tokenModel->tokenable_id)
                    ->first();
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

        $vault = ObsidianVault::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->sortByDesc(function ($v) {
                return ObsidianDocument::withoutGlobalScopes()->where('vault_id', $v->id)->count();
            })
            ->first();
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

        $summary = $users->map(function ($u) use ($tenantId, $totalDocs) {
            $readCount = $u->readProgress->count();

            $isRoleAdmin = ($u->role === 'administrador' || $u->role === 'admin' || $u->role === 'supervisor');
            $roleNameLower = mb_strtolower($u->jobRole?->name ?? '', 'UTF-8');
            $isAdminTitle = str_contains($roleNameLower, 'administrador') || str_contains($roleNameLower, 'gerente') || str_contains($roleNameLower, 'director');
            $isUserAdmin = $isRoleAdmin || $isAdminTitle;

            if ($isUserAdmin) {
                $userTotalDocs = $totalDocs;
            } else {
                if ($u->job_role_id) {
                    $userTotalDocs = DB::table('obsidian_document_job_role')
                        ->where('tenant_id', $tenantId)
                        ->where('job_role_id', $u->job_role_id)
                        ->count();
                } else {
                    $userTotalDocs = 0;
                }
            }

            $percentage = $userTotalDocs > 0 ? round(($readCount / $userTotalDocs) * 100, 1) : 0;
            if ($percentage > 100) $percentage = 100;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'job_role' => $u->jobRole?->name ?? 'Colaborador',
                'read_count' => $readCount,
                'total_docs' => $userTotalDocs,
                'percentage' => $percentage
            ];
        });

        return response()->json($summary);
    }

    /**
     * Public self-registration for manual readers.
     */
    public function publicRegister(Request $request, $tenantSlug)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255',
            'password' => 'required|string|min:4',
            'job_role_id' => 'nullable|integer',
        ]);

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $exists = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $request->email)
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'El correo o usuario ya se encuentra registrado.'], 400);
        }

        $role = 'colaborador';
        $jobRole = null;
        if ($request->job_role_id) {
            $jobRole = DB::table('job_roles')->where('id', $request->job_role_id)->first();
            if ($jobRole && str_contains(mb_strtolower(Str::ascii($jobRole->name), 'UTF-8'), 'administrador')) {
                $role = 'admin';
            }
        }

        $user = ObsidianUser::create([
            'tenant_id' => $tenant->id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'job_role_id' => $request->job_role_id,
            'role' => $role
        ]);

        $token = $user->createToken('vault-user-token')->plainTextToken;

        return response()->json([
            'valid' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'job_role_id' => $user->job_role_id,
                'job_role_name' => $jobRole?->name ?? 'N/A',
            ],
            'token' => $token
        ]);
    }

    public function reorderDocuments(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $order = $request->order; // Array of ids
        if (!is_array($order)) {
            return response()->json(['error' => 'Formato de orden inválido.'], 400);
        }

        DB::transaction(function () use ($tenantId, $order) {
            foreach ($order as $index => $id) {
                DB::table('obsidian_documents')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $id)
                    ->update(['position' => $index]);
            }
        });

        return response()->json(['status' => 'success']);
    }

    public function getMatrix(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        // Get all job roles for this tenant
        $jobRoles = DB::table('job_roles')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('id', 'name')
            ->orderBy('name', 'asc')
            ->get();

        // Get all documents
        $documents = DB::table('obsidian_documents')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select('id', 'title', 'type', 'slug', 'position')
            ->orderBy('position', 'asc')
            ->orderBy('title', 'asc')
            ->get();

        // Get current assignments
        $assignments = DB::table('obsidian_document_job_role')
            ->where('tenant_id', $tenantId)
            ->select('document_id', 'job_role_id')
            ->get();

        return response()->json([
            'job_roles' => $jobRoles,
            'documents' => $documents,
            'assignments' => $assignments
        ]);
    }

    public function updateMatrix(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $mappings = $request->mappings; // Array of [document_id => [job_role_ids]]
        
        if (!is_array($mappings)) {
            return response()->json(['error' => 'Formato de matriz inválido.'], 400);
        }

        DB::transaction(function () use ($tenantId, $mappings) {
            // Clear existing assignments for this tenant
            DB::table('obsidian_document_job_role')
                ->where('tenant_id', $tenantId)
                ->delete();

            $insertData = [];
            foreach ($mappings as $docId => $roleIds) {
                if (!is_array($roleIds)) continue;
                foreach ($roleIds as $roleId) {
                    $insertData[] = [
                        'tenant_id' => $tenantId,
                        'document_id' => $docId,
                        'job_role_id' => $roleId,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
            }

            if (!empty($insertData)) {
                foreach (array_chunk($insertData, 200) as $chunk) {
                    DB::table('obsidian_document_job_role')->insert($chunk);
                }
            }
        });

        return response()->json(['status' => 'success']);
    }

    /**
     * Get the current user's exam progress, history and active exam.
     */
    public function getExamStatus(Request $request, $tenantSlug)
    {
        $user = $this->resolvePublicUser($request);
        if (!$user) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $jobRoleId = $user->job_role_id;
        if (!$jobRoleId) {
            return response()->json([
                'eligible' => false,
                'reason' => 'No tienes un puesto asignado en la plataforma. Solicita a administración que te asigne uno.',
                'progress_percentage' => 0,
                'certified' => false,
                'active_exam' => null,
                'attempts' => []
            ]);
        }

        // Count visible documents for this role
        $visibleDocIds = DB::table('obsidian_document_job_role')
            ->where('tenant_id', $tenant->id)
            ->where('job_role_id', $jobRoleId)
            ->pluck('document_id')
            ->toArray();

        $totalVisible = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $visibleDocIds)
            ->where('type', 'nota')
            ->count();

        $readCount = ObsidianReadProgress::where('user_id', $user->id)
            ->whereIn('document_id', $visibleDocIds)
            ->count();

        $progressPercentage = $totalVisible > 0 ? round(($readCount * 100) / $totalVisible) : 100;
        $eligible = ($progressPercentage >= 100 && $totalVisible > 0);

        // Fetch active exam if already generated
        $activeExam = ObsidianExam::where('user_id', $user->id)
            ->where('job_role_id', $jobRoleId)
            ->with(['questions' => function ($q) {
                $q->select('id', 'exam_id', 'question_text', 'options'); // Do not expose correct_option to frontend!
            }])
            ->first();

        // Fetch attempts
        $attempts = ObsidianExamAttempt::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $certified = $attempts->where('passed', true)->count() > 0;

        return response()->json([
            'eligible' => $eligible,
            'total_visible_topics' => $totalVisible,
            'read_topics' => $readCount,
            'progress_percentage' => $progressPercentage,
            'certified' => $certified,
            'active_exam' => $activeExam,
            'attempts' => $attempts
        ]);
    }

    /**
     * Generate a new certification exam via Gemini API.
     */
    public function generateExam(Request $request, $tenantSlug)
    {
        $user = $this->resolvePublicUser($request);
        if (!$user) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $jobRoleId = $user->job_role_id;
        if (!$jobRoleId) {
            return response()->json(['error' => 'No tienes un puesto asignado.'], 400);
        }

        // 1. Verify eligibility (100% progress)
        $visibleDocIds = DB::table('obsidian_document_job_role')
            ->where('tenant_id', $tenant->id)
            ->where('job_role_id', $jobRoleId)
            ->pluck('document_id')
            ->toArray();

        $totalVisible = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $visibleDocIds)
            ->where('type', 'nota')
            ->count();

        $readCount = ObsidianReadProgress::where('user_id', $user->id)
            ->whereIn('document_id', $visibleDocIds)
            ->count();

        if ($totalVisible == 0 || $readCount < $totalVisible) {
            return response()->json(['error' => 'Debes completar el 100% de la lectura obligatoria de tu puesto antes de tomar el examen.'], 400);
        }

        // 2. Check if exam already exists
        $existing = ObsidianExam::where('user_id', $user->id)
            ->where('job_role_id', $jobRoleId)
            ->with(['questions' => function ($q) {
                $q->select('id', 'exam_id', 'question_text', 'options');
            }])
            ->first();

        if ($existing) {
            return response()->json(['active_exam' => $existing]);
        }

        // 3. Fetch contents of visible documents to formulate Gemini prompt
        $docs = ObsidianDocument::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $visibleDocIds)
            ->where('type', 'nota')
            ->select('title', 'raw_content')
            ->get();

        $contextText = "";
        foreach ($docs as $doc) {
            $contextText .= "CAPÍTULO: " . $doc->title . "\nCONTENIDO:\n" . $doc->raw_content . "\n\n";
        }

        $jobRole = JobRole::withoutGlobalScopes()->find($jobRoleId);
        $roleName = $jobRole?->title ?? 'Colaborador';

        $prompt = "Eres un Evaluador Académico Corporativo Senior. Tu objetivo es generar un examen de comprensión de 10 preguntas de opción múltiple para certificar a un colaborador en el puesto de '{$roleName}' basándote ÚNICAMENTE en la documentación oficial provista a continuación.

REGLAS DE EVALUACIÓN:
1. Las preguntas deben ser sumamente específicas del manual operativo, evitando obviedades o respuestas predecibles.
2. Cada pregunta debe tener exactamente 4 opciones de respuesta etiquetadas como A, B, C, y D.
3. Solo una opción debe ser la correcta. Las otras 3 deben ser distractores verosímiles pero erróneos.
4. Responde estrictamente con un objeto JSON válido estructurado como se indica abajo. No agregues texto explicativo fuera del JSON.

DOCUMENTACIÓN DEL PUESTO:
{$contextText}

ESQUEMA JSON REQUERIDO:
{
  \"questions\": [
    {
      \"question_text\": \"Texto de la pregunta...\",
      \"options\": [
        {\"key\": \"A\", \"text\": \"Texto de la opción A...\"},
        {\"key\": \"B\", \"text\": \"Texto de la opción B...\"},
        {\"key\": \"C\", \"text\": \"Texto de la opción C...\"},
        {\"key\": \"D\", \"text\": \"Texto de la opción D...\"}
      ],
      \"correct_option\": \"A\"
    }
  ]
}";

        $vault = ObsidianVault::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $geminiKey = $vault?->gemini_api_key ?? env('GEMINI_API_KEY');

        if (!$geminiKey) {
            // Mock exam generator for sandbox/demo mode
            return $this->createMockExam($user->id, $jobRoleId, $tenant->id);
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json'
                ]
            ]);

            if ($response->failed()) {
                throw new \Exception("Error de conexión API: " . $response->body());
            }

            $resJson = $response->json();
            $text = $resJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
            
            // Clean markdown wrapper if any
            if (preg_match('/```json\s*(.*?)\s*```/s', $text, $matches)) {
                $text = $matches[1];
            }
            $text = trim($text);

            $data = json_decode($text, true);
            if (!is_array($data) || !isset($data['questions']) || count($data['questions']) < 5) {
                throw new \Exception("Gemini retornó un formato JSON inválido o incompleto: " . $text);
            }

            $exam = DB::transaction(function () use ($tenant, $jobRoleId, $user, $data) {
                $exam = ObsidianExam::create([
                    'tenant_id' => $tenant->id,
                    'job_role_id' => $jobRoleId,
                    'user_id' => $user->id
                ]);

                foreach ($data['questions'] as $q) {
                    ObsidianExamQuestion::create([
                        'exam_id' => $exam->id,
                        'question_text' => $q['question_text'],
                        'options' => $q['options'],
                        'correct_option' => strtoupper(trim($q['correct_option']))
                    ]);
                }

                return $exam;
            });

            // Reload without correct_options for response safety
            $loadedExam = ObsidianExam::where('id', $exam->id)
                ->with(['questions' => function ($q) {
                    $q->select('id', 'exam_id', 'question_text', 'options');
                }])
                ->first();

            return response()->json(['active_exam' => $loadedExam]);

        } catch (\Exception $e) {
            // Fallback to mock exam to guarantee service availability
            \Log::error("Gemini Exam Generator Error, falling back to mock: " . $e->getMessage());
            return $this->createMockExam($user->id, $jobRoleId, $tenant->id);
        }
    }

    /**
     * Submit and grade the user's exam.
     */
    public function submitExam(Request $request, $tenantSlug)
    {
        $user = $this->resolvePublicUser($request);
        if (!$user) {
            return response()->json(['error' => 'No autorizado.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->where(function ($q) use ($tenantSlug) {
            $q->where('public_slug', $tenantSlug)->orWhere('subdomain', $tenantSlug);
        })->firstOrFail();

        $request->validate([
            'exam_id' => 'required|integer',
            'answers' => 'required|array' // [{question_id: 1, chosen: 'A'}, ...]
        ]);

        $exam = ObsidianExam::where('id', $request->exam_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $questions = ObsidianExamQuestion::where('exam_id', $exam->id)->get();
        $userAnswers = $request->answers;

        $score = 0;
        $gradedAnswers = [];

        foreach ($questions as $q) {
            $userAns = collect($userAnswers)->firstWhere('question_id', $q->id);
            $chosen = $userAns ? strtoupper(trim($userAns['chosen'])) : '';
            $isCorrect = ($chosen === $q->correct_option);
            if ($isCorrect) {
                $score++;
            }

            $gradedAnswers[] = [
                'question_id' => $q->id,
                'question_text' => $q->question_text,
                'chosen' => $chosen,
                'correct_option' => $q->correct_option,
                'is_correct' => $isCorrect
            ];
        }

        $totalQuestions = count($questions);
        $passed = ($score >= 8); // Minimum 80% score to approve (8 out of 10)

        // Snapshotting inmutable fields for high availability
        $jobRole = JobRole::withoutGlobalScopes()->find($exam->job_role_id);
        $jobRoleTitle = $jobRole?->title ?? 'Puesto Desconocido';
        $userName = $user->name;

        $attempt = DB::transaction(function () use ($tenant, $user, $exam, $jobRoleTitle, $userName, $score, $totalQuestions, $passed, $gradedAnswers) {
            $attempt = ObsidianExamAttempt::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'exam_id' => $exam->id,
                'job_role_title_at_time' => $jobRoleTitle,
                'user_name_at_time' => $userName,
                'score' => $score,
                'total_questions' => $totalQuestions,
                'passed' => $passed,
                'answers' => $gradedAnswers
            ]);

            // If passed, delete the active exam configuration so they are officially certified
            if ($passed) {
                $exam->delete();
            }

            return $attempt;
        });

        return response()->json([
            'attempt' => $attempt,
            'message' => $passed ? '¡Felicidades! Has aprobado la evaluación.' : 'Evaluación no aprobada. Te sugerimos repasar el manual organizativo.'
        ]);
    }

    /**
     * Admin: List all employees with their reading progress and exam attempts.
     */
    public function getAdminAttempts(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $users = ObsidianUser::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('jobRole')
            ->get();

        $report = [];
        foreach ($users as $u) {
            $jobRoleId = $u->job_role_id;
            $totalVisible = 0;
            $readCount = 0;
            $progressPercentage = 0;

            if ($jobRoleId) {
                $visibleDocIds = DB::table('obsidian_document_job_role')
                    ->where('tenant_id', $tenantId)
                    ->where('job_role_id', $jobRoleId)
                    ->pluck('document_id')
                    ->toArray();

                $totalVisible = ObsidianDocument::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $visibleDocIds)
                    ->where('type', 'nota')
                    ->count();

                $readCount = ObsidianReadProgress::where('user_id', $u->id)
                    ->whereIn('document_id', $visibleDocIds)
                    ->count();

                $progressPercentage = $totalVisible > 0 ? round(($readCount * 100) / $totalVisible) : 0;
            }

            $userAttempts = ObsidianExamAttempt::where('user_id', $u->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $certified = $userAttempts->where('passed', true)->count() > 0;
            $highestScore = $userAttempts->max('score') ?? 0;

            $report[] = [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'job_role' => $u->jobRole?->title ?? 'Sin Puesto',
                'progress_percentage' => $progressPercentage,
                'total_topics' => $totalVisible,
                'read_topics' => $readCount,
                'certified' => $certified,
                'highest_score' => $highestScore,
                'total_attempts' => $userAttempts->count(),
                'attempts' => $userAttempts
            ];
        }

        return response()->json(['report' => $report]);
    }

    /**
     * Admin: Delete/Reset a failed attempt so the user can retake it.
     */
    public function resetAttempt(Request $request, $attemptId)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $attempt = ObsidianExamAttempt::where('id', $attemptId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        DB::transaction(function () use ($attempt) {
            // Remove active exam to force a fresh regenerate next time they click present
            ObsidianExam::where('user_id', $attempt->user_id)->delete();
            $attempt->delete();
        });

        return response()->json(['message' => 'Intento de evaluación restablecido con éxito.']);
    }

    /**
     * Create mock offline/sandbox exam configuration when Gemini API is unavailable or unconfigured.
     */
    private function createMockExam($userId, $jobRoleId, $tenantId)
    {
        $exam = DB::transaction(function () use ($userId, $jobRoleId, $tenantId) {
            $exam = ObsidianExam::create([
                'tenant_id' => $tenantId,
                'job_role_id' => $jobRoleId,
                'user_id' => $userId
            ]);

            $mockQuestions = [
                [
                    'question_text' => '¿Cuál es el canal oficial para reportar incidentes operativos mayores dentro de la empresa?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Enviar un ticket de soporte interno en la plataforma.'],
                        ['key' => 'B', 'text' => 'Comentarlo verbalmente en la comida.'],
                        ['key' => 'C', 'text' => 'Escribir un mensaje informal en WhatsApp.'],
                        ['key' => 'D', 'text' => 'Llamar al socio tecnológico externo.']
                    ],
                    'correct_option' => 'A'
                ],
                [
                    'question_text' => 'Al abrir la sucursal, ¿qué prioridad tiene el registro de novedades diarias (roll call)?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Opcional, solo si el supervisor lo solicita.'],
                        ['key' => 'B', 'text' => 'Obligatorio, debe ser completado antes de iniciar ventas.'],
                        ['key' => 'C', 'text' => 'Debe hacerse a media jornada.'],
                        ['key' => 'D', 'text' => 'Es solo para personal de limpieza.']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Qué principio rige la seguridad física de los manuales de la Receta Secreta?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Cualquier visitante puede copiarlos o compartirlos.'],
                        ['key' => 'B', 'text' => 'Son propiedad confidencial e inalienable de DecorArte.'],
                        ['key' => 'C', 'text' => 'Son públicos y descargables de internet.'],
                        ['key' => 'D', 'text' => 'Solo aplican para la administración de nóminas.']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Cuántos minutos antes del horario de apertura oficial se habilita la ventana de apertura general?',
                    'options' => [
                        ['key' => 'A', 'text' => '30 minutos'],
                        ['key' => 'B', 'text' => '15 minutos'],
                        ['key' => 'C', 'text' => '5 minutos'],
                        ['key' => 'D', 'text' => '1 hora']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Cuál es la política principal sobre la entrega de llaves físicas a subordinados no autorizados?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Está permitido si hay mucha prisa.'],
                        ['key' => 'B', 'text' => 'Queda estrictamente prohibido sin autorización del gerente general.'],
                        ['key' => 'C', 'text' => 'Se permite durante los fines de semana.'],
                        ['key' => 'D', 'text' => 'Se aconseja duplicarlas para emergencias.']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => 'Si el sistema de asistencia falla y no hay red, ¿cómo opera el Reloj Checador?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Bloquea el acceso e impide la entrada.'],
                        ['key' => 'B', 'text' => 'Guarda de manera local y encriptada (IndexedDB) para sincronizar en línea después.'],
                        ['key' => 'C', 'text' => 'Obliga a firmar en papel.'],
                        ['key' => 'D', 'text' => 'El día se considera como falta automática.']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Cuál es la tolerancia máxima permitida para registrarse tarde sin justificación de retardo?',
                    'options' => [
                        ['key' => 'A', 'text' => '10 minutos'],
                        ['key' => 'B', 'text' => '5 minutos'],
                        ['key' => 'C', 'text' => 'Ninguno, se aplica amonestación automática.'],
                        ['key' => 'D', 'text' => '20 minutos']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Qué debe hacer un colaborador si detecta una anomalía crítica en el inventario al recibir el turno?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Esperar a la junta semanal.'],
                        ['key' => 'B', 'text' => 'Registrarla de inmediato en la bitácora y notificar al supervisor.'],
                        ['key' => 'C', 'text' => 'Ignorarla si es menor a 100 pesos.'],
                        ['key' => 'D', 'text' => 'Anotarlo en una hoja suelta.']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Cuál es el objetivo principal del manual operativo La Receta Secreta?',
                    'options' => [
                        ['key' => 'A', 'text' => 'Publicitar los productos a clientes.'],
                        ['key' => 'B', 'text' => 'Estandarizar procesos para garantizar consistencia y excelencia operativa.'],
                        ['key' => 'C', 'text' => 'Llevar el cálculo del pago de nómina.'],
                        ['key' => 'D', 'text' => 'Servir de catálogo para compras externas.']
                    ],
                    'correct_option' => 'B'
                ],
                [
                    'question_text' => '¿Quién es el responsable directo de auditar que el check-list de apertura esté completo?',
                    'options' => [
                        ['key' => 'A', 'text' => 'El auxiliar de cajas.'],
                        ['key' => 'B', 'text' => 'El supervisor o encargado de turno que realiza la apertura.'],
                        ['key' => 'C', 'text' => 'Cualquier colaborador presente.'],
                        ['key' => 'D', 'text' => 'El cliente principal.']
                    ],
                    'correct_option' => 'B'
                ],
            ];

            foreach ($mockQuestions as $mq) {
                ObsidianExamQuestion::create([
                    'exam_id' => $exam->id,
                    'question_text' => $mq['question_text'],
                    'options' => $mq['options'],
                    'correct_option' => $mq['correct_option']
                ]);
            }

            return $exam;
        });

        $loadedExam = ObsidianExam::where('id', $exam->id)
            ->with(['questions' => function ($q) {
                $q->select('id', 'exam_id', 'question_text', 'options');
            }])
            ->first();

        return response()->json(['active_exam' => $loadedExam]);
    }
}
