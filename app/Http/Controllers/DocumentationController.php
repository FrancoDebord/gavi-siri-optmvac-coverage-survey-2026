<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Documentation de la plateforme, affichée dans le site à partir des fichiers Markdown du dépôt
 * (README.md et docs/*.md) : une seule source, toujours à jour avec la version déployée.
 */
class DocumentationController extends Controller
{
    /** Pages publiées : fichier source, titre, icône et permissions donnant accès (l'une suffit). */
    public const PAGES = [
        'plateforme' => [
            'file' => 'README.md',
            'title' => 'Guide de la plateforme',
            'description' => 'Modules, rôles, cycle de vie des queries, variables du formulaire, commandes.',
            'icon' => 'book',
            'permissions' => ['view dashboards', 'view pi dashboard'],
        ],
        'regles-qualite' => [
            'file' => 'docs/regles-qualite.md',
            'title' => 'Règles qualité',
            'description' => 'Liste des règles de détection des queries, sévérités, seuils et suggestions de résolution.',
            'icon' => 'sliders',
            'permissions' => ['view dashboards', 'view pi dashboard', 'manage rules'],
        ],
        'plan-gestion-donnees' => [
            'file' => 'docs/plan-gestion-donnees.md',
            'title' => 'Plan de gestion des données (PGD)',
            'description' => 'Principes GCP, rôles, flux de données, contrôle qualité, corrections, gel et versions, exports, sécurité, ALCOA+.',
            'icon' => 'shield-check',
            'permissions' => ['view dashboards', 'view pi dashboard'],
        ],
        'sop-gestion-queries' => [
            'file' => 'docs/sop-gestion-queries.md',
            'title' => 'SOP-DM-01 – Gestion des queries',
            'description' => 'Procédure : détection, affectation, réponse, validation, double validation, clôture, cas particuliers, délais.',
            'icon' => 'list-check',
            'permissions' => ['view dashboards', 'view pi dashboard', 'view team space'],
        ],
        'deploiement' => [
            'file' => 'docs/deploiement.md',
            'title' => 'Déploiement (CI/CD)',
            'description' => 'Tests et mise en production automatiques, préparation du serveur, retour arrière.',
            'icon' => 'rocket-takeoff',
            'permissions' => ['manage sources'],
        ],
    ];

    /** Pages accessibles à l'utilisateur, dans l'ordre du menu. */
    public static function availablePages(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return array_filter(self::PAGES, fn (array $page) => collect($page['permissions'])->contains(fn ($p) => $user->can($p)));
    }

    public function index(Request $request): View
    {
        $pages = self::availablePages($request->user());
        abort_if($pages === [], 403);

        return view('documentation.index', [
            'pages' => collect($pages)->map(fn (array $page, string $slug) => $page + [
                'slug' => $slug,
                'updated_at' => File::exists(base_path($page['file'])) ? CarbonImmutable::createFromTimestamp(File::lastModified(base_path($page['file']))) : null,
            ]),
        ]);
    }

    public function show(Request $request, string $page): View
    {
        abort_unless(isset(self::PAGES[$page]), 404);
        $pages = self::availablePages($request->user());
        abort_unless(isset($pages[$page]), 403);

        $path = base_path(self::PAGES[$page]['file']);
        abort_unless(File::exists($path), 404, 'Document introuvable.');

        // Rendu mis en cache tant que le fichier ne change pas (nouvelle release = nouveau contenu).
        $rendered = Cache::remember('docs:'.$page.':'.File::lastModified($path), now()->addDay(), fn () => $this->render(File::get($path), $page));

        return view('documentation.show', [
            'slug' => $page,
            'page' => self::PAGES[$page],
            'pages' => $pages,
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'updatedAt' => CarbonImmutable::createFromTimestamp(File::lastModified($path)),
        ]);
    }

    /**
     * Markdown → HTML : HTML brut supprimé, liens dangereux refusés, tableaux GitHub,
     * ancres sur les titres h2/h3 et sommaire, liens entre documents réécrits vers le site.
     *
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    private function render(string $markdown, string $current): array
    {
        $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown);
        // Le titre h1 est affiché par l'en-tête de page.
        $markdown = preg_replace('/\A\s*#\s+[^\n]*\n/', '', $markdown);

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]); // GitHub Flavored Markdown : tableaux, barré, listes de tâches, liens automatiques.

        // Ancres et sommaire.
        $toc = [];
        $used = [];
        $html = preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function ($m) use (&$toc, &$used) {
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $id = Str::slug($text) ?: 'section';
            $base = $id;
            for ($i = 2; isset($used[$id]); $i++) {
                $id = $base.'-'.$i;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'text' => $text, 'level' => (int) $m[1]];

            return sprintf('<h%1$s id="%2$s">%3$s <a href="#%2$s" class="doc-anchor" aria-label="Lien vers cette section">#</a></h%1$s>', $m[1], $id, $m[2]);
        }, $html);

        // Liens relatifs : vers une autre page de documentation, ou désactivés (fichiers du dépôt).
        $byFile = collect(self::PAGES)->mapWithKeys(fn ($p, $slug) => [basename($p['file']) => $slug]);
        $html = preg_replace_callback('#<a href="([^"]*)"([^>]*)>(.*?)</a>#s', function ($m) use ($byFile, $current) {
            $href = html_entity_decode($m[1]);
            if (preg_match('#^(https?:|mailto:|\#)#i', $href)) {
                $external = str_starts_with($href, 'http') ? ' target="_blank" rel="noopener noreferrer"' : '';

                return '<a href="'.e($href).'"'.$m[2].$external.'>'.$m[3].'</a>';
            }
            [$file, $fragment] = array_pad(explode('#', $href, 2), 2, null);
            $slug = $byFile[basename($file)] ?? null;
            if ($slug) {
                $url = $slug === $current ? '' : route('documentation.show', $slug, false);

                return '<a href="'.e($url.($fragment ? '#'.$fragment : '')).'">'.$m[3].'</a>';
            }

            // Fichier du dépôt (code, configuration) : affiché sans lien.
            return '<span class="doc-file" title="'.e($file).'">'.$m[3].'</span>';
        }, $html);

        // Tableaux : style Bootstrap et défilement horizontal sur mobile.
        $html = str_replace(['<table>', '</table>'], ['<div class="table-responsive"><table class="table table-sm table-bordered align-middle">', '</table></div>'], $html);
        $html = str_replace('<blockquote>', '<blockquote class="doc-note">', $html);

        return ['html' => $html, 'toc' => $toc];
    }
}
