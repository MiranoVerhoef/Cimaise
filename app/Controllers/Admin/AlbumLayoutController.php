<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AlbumLayoutService;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AlbumLayoutController extends BaseController
{
    public function __construct(private readonly Database $db, private readonly Twig $view)
    {
        parent::__construct();
    }

    private function images(int $albumId): array
    {
        return $this->db->query("SELECT i.id, i.width, i.height, i.alt_text, i.caption,
            (SELECT v.path FROM image_variants v WHERE v.image_id = i.id
             ORDER BY CASE v.variant WHEN 'sm' THEN 0 ELSE 1 END,
                      CASE v.format WHEN 'jpg' THEN 0 ELSE 1 END LIMIT 1) AS preview
            FROM images i WHERE i.album_id = ? ORDER BY i.sort_order, i.id", [$albumId])->fetchAll();
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $album = $this->db->query('SELECT id, title, slug FROM albums WHERE id = ?', [(int) $args['id']])->fetch();
        if (!$album) {
            return $response->withStatus(404);
        }
        $service = new AlbumLayoutService($this->db);
        $service->initialize((int) $album['id']);
        return $this->view->render($response->withHeader('Cache-Control', 'no-store'), 'admin/album_layout.twig', [
            'album' => $album,
            'layout_state' => $service->state((int) $album['id']),
            'layout_images' => $this->images((int) $album['id']),
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }
        $albumId = (int) $args['id'];
        $album = $this->db->query('SELECT slug FROM albums WHERE id = ?', [$albumId])->fetch();
        if (!$album) {
            return $response->withStatus(404);
        }
        $body = (array) $request->getParsedBody();
        if (!is_int($body['revision'] ?? null) || !is_string($body['action'] ?? null)) {
            return $this->jsonResponse($response, ['error' => 'Invalid save request.'], 422);
        }
        $service = new AlbumLayoutService($this->db);
        try {
            $layout = null;
            if ($body['action'] !== 'default') {
                if (!is_array($body['layout'] ?? null) || strlen(json_encode($body['layout'], JSON_THROW_ON_ERROR)) > 262144) {
                    throw new InvalidArgumentException('Layout is missing or too large.');
                }
                $ids = array_map('intval', array_column($this->images($albumId), 'id'));
                $layout = $service->normalize($body['layout'], $ids);
            }
            $revision = $service->save($albumId, $body['revision'], $layout, $body['action']);
        } catch (InvalidArgumentException $error) {
            return $this->jsonResponse($response, ['error' => $error->getMessage()], 422);
        } catch (\RuntimeException $error) {
            if ($error->getCode() !== 409) {
                throw $error;
            }
            return $this->jsonResponse($response, ['error' => $error->getMessage()], 409);
        }
        if ($body['action'] !== 'draft') {
            // Publishing must replace stale HTML on the very next visitor request.
            (new PageCacheService(new SettingsService($this->db), $this->db))->clearAll();
        }
        return $this->jsonResponse($response, ['ok' => true, 'revision' => $revision, 'layout' => $layout])
            ->withHeader('Cache-Control', 'no-store');
    }
}
