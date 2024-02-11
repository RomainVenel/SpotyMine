<?php

namespace App\Controller;

use App\Form\PlaylistType;
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
    #[Route('/', name: 'app_spotify_home')]
    public function home(): Response
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        $this->tokenUser();

        $user = $this->api->me();

        return $this->render('spotify/home.html.twig', [
            'user' => $user
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/createPlaylists', name: 'app_spotify_create_playlist')]
    public function createPlaylist(Request $request): Response
    {
        if (!$this->cache->hasItem('spotify_access_token')) {
            return $this->redirectToRoute('app_spotify_redirect');
        }

        $this->tokenUser();

        $user = $this->api->me();

        $form = $this->createForm(PlaylistType::class);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $playlistData = $form->getData();

            $createdPlaylist = $this->generatePlaylist($user->id, $playlistData);

            $playlist = $this->api->getPlaylist($createdPlaylist->id);

            return $this->render('spotify/index.html.twig', [
                'playlist' => $playlist
            ]);
        }

        return $this->render('spotify/createPlaylist.html.twig', [
            'user' => $user,
            'form' => $form
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/playlists', name: 'app_spotify_update_playlist')]
    public function updatePlaylist(): Response
    {

        $this->tokenUser();
        $userId = $this->api->me()->id;
        $playlistTop30 = null;

        $myPlaylists = $this->api->getMyPlaylists()->items;
        foreach ($myPlaylists as $playlist) {
            if (str_contains($playlist->name, 'TOP30')) {
                $playlistTop30 = $playlist;
            }
        }

        if (is_null($playlistTop30)) {
            $playlistTop30 = $this->generatePlaylist($userId);
        }

        $playlist = $this->api->getPlaylist($playlistTop30->id);

        return $this->render('spotify/index.html.twig', [
           'playlist' => $playlist
        ]);
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

        return $this->redirectToRoute('app_spotify_home');
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

    private function generatePlaylist($userId, $playlist): mixed
    {

        $namePlaylist = $playlist['name'];
        $limitPlaylist = $playlist['limit'];
        $rangePlaylist = $playlist['range'];

        $topPlaylist = $this->api->getMyTop('tracks', [
            'limit' => $limitPlaylist,
            'time_range' => $rangePlaylist . '_term',
        ]);

        $this->api->createPlaylist($userId, [
            'name' => $namePlaylist
        ]);

        $createdPlaylist = $this->api->getMyPlaylists()->items[0];
        $arrayTop30Id  = array_map(function($track) {
            return $track->id;
        }, $topPlaylist->items);

        $this->api->replacePlaylistTracks($createdPlaylist->id, $arrayTop30Id);

        return $createdPlaylist;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function tokenUser(): void
    {
        $this->api->setAccessToken($this->cache->getItem('spotify_access_token')->get());
    }
}
