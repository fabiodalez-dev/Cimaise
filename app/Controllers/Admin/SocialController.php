<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\SettingsService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class SocialController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function show(Request $request, Response $response): Response
    {
        $svc = new SettingsService($this->db);
        
        // Get current social settings
        $enabledSocials = $svc->get('social.enabled', []);
        if (!is_array($enabledSocials)) {
            $enabledSocials = $this->getDefaultEnabledSocials();
        }
        
        // Get social order
        $socialOrder = $svc->get('social.order', []);
        if (!is_array($socialOrder)) {
            $socialOrder = $enabledSocials;
        }
        
        // Get all available social networks
        $availableSocials = $this->getAvailableSocials();
        
        return $this->view->render($response, 'admin/social.twig', [
            'enabled_socials' => $enabledSocials,
            'social_order' => $socialOrder,
            'available_socials' => $availableSocials,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        // Support both form-encoded and JSON payloads
        $data = (array)($request->getParsedBody() ?? []);
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        // For JSON payloads, parse the body first to get CSRF token
        if (str_contains($contentType, 'application/json')) {
            try {
                $raw = (string)$request->getBody();
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            } catch (\Throwable) {
                // fall back to parsed body if JSON decoding fails
            }
        }

        // CSRF validation (check from parsed data or header)
        $token = $data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        if (!\is_string($token) || !isset($_SESSION['csrf']) || !\hash_equals($_SESSION['csrf'], $token)) {
            if ($this->isAjaxRequest($request)) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Invalid CSRF token'];
            return $response->withHeader('Location', $this->redirect('/admin/social'))->withStatus(302);
        }

        $svc = new SettingsService($this->db);
        
        // Determine enabled socials
        $enabledSocials = [];
        $availableSocials = $this->getAvailableSocials();
        $availableKeys = array_keys($availableSocials);

        // Case 1: JSON payload with explicit enabled list
        if (isset($data['enabled']) && is_array($data['enabled'])) {
            // sanitize: keep only valid keys and preserve order
            foreach ($data['enabled'] as $key) {
                if (in_array($key, $availableKeys, true)) {
                    $enabledSocials[] = $key;
                }
            }
        } else {
            // Case 2: form fields social_<key>=on
            foreach ($availableSocials as $social => $config) {
                if (isset($data['social_' . $social])) {
                    $enabledSocials[] = $social;
                }
            }
        }
        
        // Get social order from form data (if provided)
        $socialOrder = [];
        if (isset($data['order']) && is_array($data['order'])) {
            $orderData = array_values(array_filter($data['order'], fn($k) => in_array($k, $availableKeys, true)));
            // Filter order to only include enabled socials, preserve provided order
            $socialOrder = array_values(array_intersect($orderData, $enabledSocials));
            // Add any enabled socials not in the order to the end
            $socialOrder = array_values(array_merge($socialOrder, array_diff($enabledSocials, $socialOrder)));
        } elseif (isset($data['social_order']) && is_string($data['social_order'])) {
            $orderData = json_decode($data['social_order'], true);
            if (is_array($orderData)) {
                $orderData = array_values(array_filter($orderData, fn($k) => in_array($k, $availableKeys, true)));
                $socialOrder = array_values(array_intersect($orderData, $enabledSocials));
                $socialOrder = array_values(array_merge($socialOrder, array_diff($enabledSocials, $socialOrder)));
            }
        }
        
        // If no order provided, use enabled socials in default order
        if (empty($socialOrder)) {
            $socialOrder = $enabledSocials;
        }
        
        // Save settings
        $svc->set('social.enabled', $enabledSocials);
        $svc->set('social.order', $socialOrder);
        
        // AJAX request support: return JSON instead of redirect
        if ($this->isAjaxRequest($request)) {
            $payload = json_encode([
                'ok' => true,
                'enabled' => $enabledSocials,
                'order' => $socialOrder,
            ]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = ['type'=>'success','message'=>'Social settings saved successfully'];
        return $response->withHeader('Location', $this->redirect('/admin/social'))->withStatus(302);
    }

    private function getDefaultEnabledSocials(): array
    {
        return ['behance','whatsapp','facebook','x','deviantart','instagram','pinterest','telegram','threads','bluesky'];
    }

    private function getAvailableSocials(): array
    {
        return [
            'addtofavorites' => [
                'name' => 'Add to Favorites',
                'icon' => 'fa fa-star',
                'color' => '#F9A600',
                'url' => '#' // Not a real sharing service
            ],
            'behance' => [
                'name' => 'Behance',
                'icon' => 'fab fa-behance',
                'color' => '#1769ff',
                'url' => 'https://www.behance.net/gallery/share?title={title}&url={url}'
            ],
            'bitbucket' => [
                'name' => 'Bitbucket',
                'icon' => 'fab fa-bitbucket',
                'color' => '#205081',
                'url' => '#' // Not a sharing service
            ],
            'blogger' => [
                'name' => 'Blogger',
                'icon' => 'fab fa-blogger-b',
                'color' => '#FF6501',
                'url' => 'https://www.blogger.com/blog_this.pyra?t&u={url}&n={title}'
            ],
            'bluesky' => [
                'name' => 'Bluesky',
                'icon' => 'fab fa-bluesky',
                'color' => '#1083fe',
                'url' => 'https://bsky.app/intent/compose?text={title} {url}'
            ],
            'codepen' => [
                'name' => 'CodePen',
                'icon' => 'fab fa-codepen',
                'color' => '#000',
                'url' => '#' // Not a sharing service
            ],
            'comments' => [
                'name' => 'Comments',
                'icon' => 'fa fa-comments',
                'color' => '#333',
                'url' => '#' // Not a sharing service
            ],
            'delicious' => [
                'name' => 'Delicious',
                'icon' => 'fab fa-delicious',
                'color' => '#3274D1',
                'url' => 'https://delicious.com/save?url={url}&title={title}'
            ],
            'deviantart' => [
                'name' => 'DeviantArt',
                'icon' => 'fab fa-deviantart',
                'color' => '#475c4d',
                'url' => 'https://www.deviantart.com/users/outgoing?{url}'
            ],
            'digg' => [
                'name' => 'Digg',
                'icon' => 'fab fa-digg',
                'color' => '#000',
                'url' => 'https://digg.com/submit?url={url}&title={title}'
            ],
            'discord' => [
                'name' => 'Discord',
                'icon' => 'fab fa-discord',
                'color' => '#7289da',
                'url' => '#' // Not a web sharing service
            ],
            'dribbble' => [
                'name' => 'Dribbble',
                'icon' => 'fab fa-dribbble',
                'color' => '#ea4c89',
                'url' => '#' // Not a sharing service
            ],
            'email' => [
                'name' => 'Email',
                'icon' => 'fa fa-envelope',
                'color' => '#000',
                'url' => 'mailto:?subject={title}&body={url}'
            ],
            'etsy' => [
                'name' => 'Etsy',
                'icon' => 'fab fa-etsy',
                'color' => '#f1641e',
                'url' => '#' // Not a sharing service
            ],
            'facebook' => [
                'name' => 'Facebook',
                'icon' => 'fab fa-facebook-f',
                'color' => '#0866ff',
                'url' => 'https://www.facebook.com/sharer/sharer.php?u={url}'
            ],
            'fbmessenger' => [
                'name' => 'Facebook Messenger',
                'icon' => 'fab fa-facebook-messenger',
                'color' => '#0866ff',
                'url' => 'https://www.facebook.com/dialog/send?link={url}'
            ],
            'flickr' => [
                'name' => 'Flickr',
                'icon' => 'fab fa-flickr',
                'color' => '#1c9be9',
                'url' => '#' // Not a sharing service
            ],
            'flipboard' => [
                'name' => 'Flipboard',
                'icon' => 'fab fa-flipboard',
                'color' => '#F52828',
                'url' => 'https://share.flipboard.com/bookmarklet/popout?v=2&url={url}&title={title}'
            ],
            'github' => [
                'name' => 'GitHub',
                'icon' => 'fab fa-github',
                'color' => '#333',
                'url' => '#' // Not a sharing service
            ],
            'google' => [
                'name' => 'Google',
                'icon' => 'fab fa-google',
                'color' => '#3A7CEC',
                'url' => '#' // Not a sharing service
            ],
            'googleplus' => [
                'name' => 'Google+',
                'icon' => 'fab fa-google-plus-g',
                'color' => '#DB483B',
                'url' => 'https://plus.google.com/share?url={url}'
            ],
            'hackernews' => [
                'name' => 'Hacker News',
                'icon' => 'fab fa-hacker-news',
                'color' => '#FF6500',
                'url' => 'https://news.ycombinator.com/submitlink?u={url}&t={title}'
            ],
            'houzz' => [
                'name' => 'Houzz',
                'icon' => 'fab fa-houzz',
                'color' => '#4dbc15',
                'url' => '#' // Not a sharing service
            ],
            'instagram' => [
                'name' => 'Instagram',
                'icon' => 'fab fa-instagram',
                'color' => '#e23367',
                'url' => 'https://www.instagram.com/' // Not a direct sharing service
            ],
            'line' => [
                'name' => 'Line',
                'icon' => 'fab fa-line',
                'color' => '#00C300',
                'url' => 'https://lineit.line.me/share/ui?url={url}&text={title}'
            ],
            'linkedin' => [
                'name' => 'LinkedIn',
                'icon' => 'fab fa-linkedin-in',
                'color' => '#0274B3',
                'url' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}'
            ],
            'mastodon' => [
                'name' => 'Mastodon',
                'icon' => 'fab fa-mastodon',
                'color' => '#6364ff',
                'url' => '#' // Requires instance-specific URL
            ],
            'medium' => [
                'name' => 'Medium',
                'icon' => 'fab fa-medium',
                'color' => '#02b875',
                'url' => 'https://medium.com/new-story?url={url}&title={title}'
            ],
            'mix' => [
                'name' => 'Mix',
                'icon' => 'fab fa-mix',
                'color' => '#ff8226',
                'url' => 'https://mix.com/add?url={url}&title={title}'
            ],
            'odnoklassniki' => [
                'name' => 'Odnoklassniki',
                'icon' => 'fab fa-odnoklassniki',
                'color' => '#F2720C',
                'url' => 'https://connect.ok.ru/dk?st.cmd=WidgetSharePreview&st.shareUrl={url}&st.comments={title}'
            ],
            'patreon' => [
                'name' => 'Patreon',
                'icon' => 'fab fa-patreon',
                'color' => '#e85b46',
                'url' => '#' // Not a sharing service
            ],
            'paypal' => [
                'name' => 'PayPal',
                'icon' => 'fab fa-paypal',
                'color' => '#0070ba',
                'url' => '#' // Not a sharing service
            ],
            'pdf' => [
                'name' => 'PDF',
                'icon' => 'fa fa-file-pdf',
                'color' => '#E61B2E',
                'url' => '#' // Not a sharing service
            ],
            'phone' => [
                'name' => 'Phone',
                'icon' => 'fa fa-phone',
                'color' => '#1A73E8',
                'url' => '#' // Not a sharing service
            ],
            'pinterest' => [
                'name' => 'Pinterest',
                'icon' => 'fab fa-pinterest',
                'color' => '#CB2027',
                'url' => 'https://pinterest.com/pin/create/button/?url={url}&description={title}'
            ],
            'pocket' => [
                'name' => 'Pocket',
                'icon' => 'fab fa-get-pocket',
                'color' => '#EF4056',
                'url' => 'https://getpocket.com/save?url={url}&title={title}'
            ],
            'podcast' => [
                'name' => 'Podcast',
                'icon' => 'fa fa-podcast',
                'color' => '#7224d8',
                'url' => '#' // Not a sharing service
            ],
            'print' => [
                'name' => 'Print',
                'icon' => 'fa fa-print',
                'color' => '#6D9F00',
                'url' => 'javascript:window.print()'
            ],
            'reddit' => [
                'name' => 'Reddit',
                'icon' => 'fab fa-reddit',
                'color' => '#FF5600',
                'url' => 'https://www.reddit.com/submit?url={url}&title={title}'
            ],
            'renren' => [
                'name' => 'Renren',
                'icon' => 'fab fa-renren',
                'color' => '#005EAC',
                'url' => 'https://www.connect.renren.com/share/sharer?url={url}&title={title}'
            ],
            'rss' => [
                'name' => 'RSS',
                'icon' => 'fa fa-rss',
                'color' => '#FF7B0A',
                'url' => '#' // Not a sharing service
            ],
            'shortlink' => [
                'name' => 'Short Link',
                'icon' => 'fa fa-link',
                'color' => '#333',
                'url' => '#' // Not a sharing service
            ],
            'skype' => [
                'name' => 'Skype',
                'icon' => 'fab fa-skype',
                'color' => '#00AFF0',
                'url' => 'https://web.skype.com/share?url={url}&text={title}'
            ],
            'sms' => [
                'name' => 'SMS',
                'icon' => 'fa fa-sms',
                'color' => '#35d54f',
                'url' => 'sms:?body={title} {url}'
            ],
            'snapchat' => [
                'name' => 'Snapchat',
                'icon' => 'fab fa-snapchat',
                'color' => '#FFFC00',
                'url' => '#' // Not a web sharing service
            ],
            'soundcloud' => [
                'name' => 'SoundCloud',
                'icon' => 'fab fa-soundcloud',
                'color' => '#f50',
                'url' => '#' // Not a sharing service
            ],
            'stackoverflow' => [
                'name' => 'Stack Overflow',
                'icon' => 'fab fa-stack-overflow',
                'color' => '#F48024',
                'url' => '#' // Not a sharing service
            ],
            'quora' => [
                'name' => 'Quora',
                'icon' => 'fab fa-quora',
                'color' => '#b92b27',
                'url' => 'https://www.quora.com/share?url={url}&title={title}'
            ],
            'telegram' => [
                'name' => 'Telegram',
                'icon' => 'fab fa-telegram-plane',
                'color' => '#179cde',
                'url' => 'https://t.me/share/url?url={url}&text={title}'
            ],
            'threads' => [
                'name' => 'Threads',
                'icon' => 'fab fa-threads',
                'color' => '#000',
                'url' => 'https://www.threads.net/intent/post?text={title} {url}'
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'icon' => 'fab fa-tiktok',
                'color' => '#010101',
                'url' => '#' // Not a web sharing service
            ],
            'tumblr' => [
                'name' => 'Tumblr',
                'icon' => 'fab fa-tumblr',
                'color' => '#314358',
                'url' => 'https://www.tumblr.com/widgets/share/tool?shareSource=legacy&canonicalUrl={url}&title={title}'
            ],
            'twitch' => [
                'name' => 'Twitch',
                'icon' => 'fab fa-twitch',
                'color' => '#4b367c',
                'url' => '#' // Not a sharing service
            ],
            'twitter' => [
                'name' => 'Twitter',
                'icon' => 'fab fa-twitter',
                'color' => '#1da1f2',
                'url' => 'https://twitter.com/intent/tweet?text={title}&url={url}'
            ],
            'viber' => [
                'name' => 'Viber',
                'icon' => 'fab fa-viber',
                'color' => '#574e92',
                'url' => 'viber://forward?text={title} {url}'
            ],
            'vimeo' => [
                'name' => 'Vimeo',
                'icon' => 'fab fa-vimeo',
                'color' => '#00ADEF',
                'url' => '#' // Not a sharing service
            ],
            'vkontakte' => [
                'name' => 'VKontakte',
                'icon' => 'fab fa-vk',
                'color' => '#4C75A3',
                'url' => 'https://vk.com/share.php?url={url}&title={title}'
            ],
            'wechat' => [
                'name' => 'WeChat',
                'icon' => 'fab fa-weixin',
                'color' => '#7BB32E',
                'url' => '#' // Not a web sharing service
            ],
            'weibo' => [
                'name' => 'Weibo',
                'icon' => 'fab fa-weibo',
                'color' => '#E6162D',
                'url' => 'https://service.weibo.com/share/share.php?url={url}&title={title}'
            ],
            'whatsapp' => [
                'name' => 'WhatsApp',
                'icon' => 'fab fa-whatsapp',
                'color' => '#25d366',
                'url' => 'https://wa.me/?text={title} {url}'
            ],
            'x' => [
                'name' => 'X (Twitter)',
                'icon' => 'fab fa-x-twitter',
                'color' => '#000',
                'url' => 'https://twitter.com/intent/tweet?text={title}&url={url}'
            ],
            'xing' => [
                'name' => 'Xing',
                'icon' => 'fab fa-xing',
                'color' => '#006567',
                'url' => 'https://www.xing.com/app/user?op=share;url={url};title={title}'
            ],
            'yahoomail' => [
                'name' => 'Yahoo Mail',
                'icon' => 'fab fa-yahoo',
                'color' => '#4A00A1',
                'url' => 'https://compose.mail.yahoo.com/?to=&subject={title}&body={url}'
            ],
            'youtube' => [
                'name' => 'YouTube',
                'icon' => 'fab fa-youtube',
                'color' => '#ff0000',
                'url' => '#' // Not a sharing service
            ],
            'more' => [
                'name' => 'More',
                'icon' => 'fa fa-share-nodes',
                'color' => 'green',
                'url' => 'javascript:navigator.share({title: "{title}", url: "{url}"})'
            ]
        ];
    }

}
