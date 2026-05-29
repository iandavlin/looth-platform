<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

$kind = $_GET['kind'] ?? '';
header('Cache-Control: public, max-age=300');

$pg = Db::pg();
switch ($kind) {
    case 'instruments':
        $rows = $pg->query("SELECT id, slug, name, type, subtype, sort_order
                            FROM instrument_catalog WHERE active=true
                            ORDER BY sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
            'type' => $r['type'], 'subtype' => $r['subtype'],
        ], $rows)]);
    case 'skills':
        $rows = $pg->query("SELECT id, slug, name, category, sort_order
                            FROM skill_catalog WHERE active=true
                            ORDER BY category, sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
            'category' => $r['category'],
        ], $rows)]);
    case 'scenes':
        $rows = $pg->query("SELECT slug, name FROM scene_tags WHERE active=true
                            ORDER BY sort_order, name")->fetchAll();
        profile_app_json(200, ['items' => $rows]);
    case 'credentials':
        $q = $_GET['q'] ?? '';
        $params = [];
        $where = 'WHERE active=true';
        if (is_string($q) && trim($q) !== '') {
            $where .= ' AND (issuer ILIKE :q OR program ILIKE :q OR slug ILIKE :q)';
            $params[':q'] = '%' . trim($q) . '%';
        }
        $stmt = $pg->prepare("SELECT id, slug, category, issuer, program, logo_url
                              FROM credential_catalog $where
                              ORDER BY category, issuer, program LIMIT 50");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        profile_app_json(200, ['items' => array_map(fn($r) => [
            'id' => (int)$r['id'], 'slug' => $r['slug'], 'category' => $r['category'],
            'issuer' => $r['issuer'], 'program' => $r['program'], 'logo_url' => $r['logo_url'],
        ], $rows)]);
    default:
        profile_app_json(404, ['error' => 'unknown_catalog']);
}
