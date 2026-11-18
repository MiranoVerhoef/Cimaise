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
        $images = $this->db->query('SELECT id, width, height, alt_text, caption
            FROM images WHERE album_id = ? ORDER BY sort_order, id', [$albumId])->fetchAll();
        foreach ($images as &$image) {
            $image['preview'] = '/admin/media/images/' . $image['id'] . '/variants/sm.jpg';
        }
        unset($image);
        return $images;
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
            'layout_history' => $service->history((int) $album['id']),
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
        $action = $body['action'];
        try {
            $layout = null;
            if ($action !== 'default') {
                $ids = array_map('intval', array_column($this->images($albumId), 'id'));
                if ($action === 'restore') {
                    $version = $body['version'] ?? null;
                    if (!is_array($version) || !is_int($version['revision'] ?? null) || !is_string($version['kind'] ?? null)) {
                        throw new InvalidArgumentException('Select a layout version.');
                    }
                    $snapshot = $service->snapshot($albumId, $version['revision'], $version['kind']);
                    if ($snapshot === null) {
                        return $this->jsonResponse($response, ['error' => 'This layout version is no longer available.'], 404);
                    }
                    $layout = $service->normalize($service->removeMissingPhotos($snapshot, $ids), $ids);
                } else {
                    if (!is_array($body['layout'] ?? null) || strlen(json_encode($body['layout'], JSON_THROW_ON_ERROR)) > 262144) {
                        throw new InvalidArgumentException('Layout is missing or too large.');
                    }
                    $layout = $service->normalize($body['layout'], $ids);
                }
            }
            $revision = $service->save($albumId, $body['revision'], $layout, $action);
        } catch (InvalidArgumentException $error) {
            return $this->jsonResponse($response, ['error' => $error->getMessage()], 422);
        } catch (\RuntimeException $error) {
            if ($error->getCode() !== 409) {
                throw $error;
            }
            return $this->jsonResponse($response, ['error' => $error->getMessage()], 409);
        }
        if (in_array($action, ['publish', 'default'], true)) {
            // Publishing must replace stale HTML on the very next visitor request.
            (new PageCacheService(new SettingsService($this->db), $this->db))->clearAll();
        }
        return $this->jsonResponse($response, ['ok' => true, 'revision' => $revision, 'layout' => $layout,
            'history' => $service->history($albumId)])
            ->withHeader('Cache-Control', 'no-store');
    }
}
