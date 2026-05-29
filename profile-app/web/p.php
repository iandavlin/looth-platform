<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render_practice.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Practice;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$practice = Practice::loadBySlug($slug);
if (!$practice) { http_response_code(404); echo 'not found'; exit; }

$viewer    = Auth::currentUser();
$viewerId  = $viewer ? (int)$viewer['id'] : 0;
$rendered  = Practice::renderForViewer($practice, $viewerId);
$members   = Practice::members($practice['id']);

looth_render_practice($rendered, $members);
