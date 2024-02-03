<?php

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use SpotifyWebAPI\Session;
use SpotifyWebAPI\SpotifyWebAPIAuthException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SpotifyWebAPI\SpotifyWebAPI;

class SpotifyController extends AbstractController
{
    public function __construct(
        private readonly SpotifyWebAPI $api,
        private readonly Session $session,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/', name: 'app_spotify_update_playlist')]
    public function updatePlaylist(): Response
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        $this->api->setAccessToken($this->cache->getItem('spotify_access_token')->get());
        $top30 = $this->api->getMyTop('tracks', [
            'limit' => 30,
            'time_range' => 'short_term',
        ]);

        dd($top30);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/callback', name: 'app_spotify_callback')]
    public function callbackFromSpotify(Request $request): Response
    {
        try {
            $this->session->requestAccessToken($request->query->get('code'));
        } catch (SpotifyWebAPIAuthException $e) {
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $cacheItem = $this->cache->getItem('spotify_access_token');
        $cacheItem->set($this->session->getAccessToken());
        $cacheItem->expiresAfter(3600);
        $this->cache->save($cacheItem);

        return $this->redirectToRoute('app_spotify_update_playlist');
    }
    #[Route('/redirect', name: 'app_spotify_redirect')]
    public function redirectToSpotify(): Response
    {
        $options = [
            'scope' => [
                'user-read-email',
                'user-read-private',
                'playlist-read-private',
                'playlist-modify-private',
                'playlist-modify-public',
                'user-top-read',
            ],
        ];

        return $this->redirect($this->session->getAuthorizeUrl($options));
    }
}
