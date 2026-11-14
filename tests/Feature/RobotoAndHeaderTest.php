<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * R-52: Roboto everywhere (self-hosted), and the shared dot-ui header with the platform's sidebar as the phone menu.
 */
class RobotoAndHeaderTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function fixture(): object
    {
        return $this->teamFixture();
    }

    // ── Typography ─────────────────────────────────────────────────────────

    public function test_every_layout_declares_roboto_from_this_site_and_no_font_leaves_it(): void
    {
        $f = $this->fixture();
        $pages = [
            'app layout' => $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()->getContent(),
            'welcome' => file_get_contents(resource_path('views/welcome.blade.php')),
            'guest layout' => file_get_contents(resource_path('views/layouts/guest.blade.php')),
            'error layout' => file_get_contents(resource_path('views/errors/_ecosystem-layout.blade.php')),
        ];

        foreach ($pages as $name => $html) {
            $this->assertStringContainsString('dot-roboto', $html, $name.': Roboto base class on <html>');
            $this->assertStringContainsString('dot-ui/css/dot-roboto.css', $html, $name.': the font-face file');
            $this->assertStringContainsString('dot-ui/css/dot-type.css', $html, $name.': the type scale');
            $this->assertDoesNotMatchRegularExpression('#fonts\.(googleapis|gstatic)\.com|cdn\.|Schibsted|Karla|Space Mono|Material\+Symbols|Figtree#i', $html, $name.': no third-party or retired typeface');
        }
    }

    public function test_the_roboto_files_are_served_from_the_origin_and_the_face_is_declared(): void
    {
        $css = file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $css);
        preg_match_all('/url\(([^)]+)\)/', $css, $urls);
        $this->assertNotEmpty($urls[1]);
        foreach ($urls[1] as $url) {
            $url = trim($url, '\'" ');
            $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, 'a font file from outside this site');
            $this->assertFileExists(dirname(public_path('dot-ui/css/dot-roboto.css')).'/'.$url);
        }
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/OFL.txt'));
    }

    public function test_the_app_font_token_is_roboto_and_no_stylesheet_names_a_retired_face_or_a_literal_size(): void
    {
        $tokens = file_get_contents(resource_path('css/job-card.css'));
        $this->assertStringContainsString("--font: var(--dot-font-sans, 'Roboto'", $tokens);

        foreach (File::glob(resource_path('css/*.css')) as $file) {
            $css = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/Schibsted|Archivo|Big Shoulders|Karla|Figtree/i', $css, basename($file).' names a retired typeface');
            $this->assertDoesNotMatchRegularExpression('/font-size:\s*\d+(\.\d+)?px/', $css, basename($file).' sets a literal font size: use the dot-ui scale tokens');
        }
        // The dead Figtree config and the unused stock Jetstream bar are gone; Tailwind takes the shared preset.
        $this->assertStringNotContainsString('Figtree', file_get_contents(base_path('tailwind.config.js')));
        $this->assertStringContainsString('dot-ui/tailwind/tailwind-preset.cjs', file_get_contents(base_path('tailwind.config.js')));
        $this->assertFileDoesNotExist(resource_path('views/navigation-menu.blade.php'));
    }

    public function test_the_policy_names_no_font_origin(): void
    {
        $f = $this->fixture();
        $policy = (string) $this->actingAs($f->owner)->get(route('dashboard'))->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('fonts.', $policy);
        $this->assertStringContainsString("font-src 'self' data:", $policy);
    }

    public function test_an_error_page_is_roboto_with_no_icon_font(): void
    {
        $this->get('/this-page-does-not-exist-r52')->assertStatus(404)->assertSee('dot-ui/css/dot-roboto.css', false)->assertDontSee('material-symbols', false);
    }

    // ── The header ─────────────────────────────────────────────────────────

    public function test_the_signed_in_header_is_the_shared_one_with_search_apps_bell_and_account(): void
    {
        $f = $this->fixture();
        $html = $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<header class="dh"'), 'one header');
        $this->assertSame(0, substr_count($html, 'class="topbar"'), 'the old bar is gone');
        $this->assertStringContainsString('class="dh-brand"', $html);
        $this->assertStringContainsString('data-dh-search', $html);
        $this->assertStringContainsString('data-dsw', $html, 'platform switcher');
        $this->assertStringContainsString('dh-bell', $html, 'notifications');
        $this->assertStringContainsString('dh-account__btn', $html, 'account menu');
        $this->assertStringContainsString('data-dh-theme-set="dark"', $html, 'theme lives in the account menu');
        $this->assertStringNotContainsString('id="theme-toggle"', $html);

        // The account menu: profile, team settings, notifications and a POST sign out with the CSRF token.
        $this->assertSame(1, preg_match('/<div id="dh-account-menu".*?<\/div>\s*<\/div>\s*<\/div>/s', $html, $menu));
        $this->assertStringContainsString('href="'.route('profile.show').'"', $menu[0]);
        $this->assertStringContainsString('href="'.route('teams.show', $f->team->id).'"', $menu[0]);
        $this->assertStringContainsString('href="'.route('notifications.index').'"', $menu[0]);
        $this->assertStringContainsString('action="'.route('logout').'"', $menu[0]);
        $this->assertStringContainsString('name="_token"', $menu[0]);
    }

    public function test_the_phone_menu_is_the_sidebar_with_new_task_notifications_account_and_a_close_button(): void
    {
        $f = $this->fixture();
        $html = $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dh-menu-button', $html);
        $this->assertStringContainsString('aria-controls="shell-drawer"', $html);
        $this->assertSame(0, preg_match('/<div class="dh-drawer"/', $html), 'no second drawer: the sidebar is the menu');
        $this->assertSame(1, preg_match('/<aside id="shell-drawer".*?<\/aside>/s', $html, $aside));
        $this->assertStringContainsString('data-dh-close', $aside[0]);
        $this->assertSame(1, preg_match('/<div class="nav-phone">.*?<\/div>/s', $aside[0], $phone), 'New task and Notifications are in the menu for phones');
        $this->assertStringContainsString('data-open-quick-add', $phone[0]);
        $this->assertStringContainsString('href="'.route('notifications.index').'"', $phone[0]);
        $this->assertStringContainsString('href="'.route('profile.show').'"', $aside[0], 'account stays reachable on a phone');
        $this->assertStringContainsString('action="'.route('logout').'"', $aside[0]);
        // Primary navigation is not duplicated in the bar.
        $this->assertSame(0, substr_count($html, '<a class="dh-nav__link"'));
    }

    public function test_a_guest_page_has_no_header_search(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-dh-search', $html);
        $this->assertStringNotContainsString('<header class="dh"', $html);
    }

    public function test_shortcut_ownership_is_documented_in_the_script_and_the_pill_claims_no_chord(): void
    {
        $js = file_get_contents(resource_path('js/shell.js'));
        $this->assertStringContainsString('Ctrl K / Cmd K  the find palette', $js);
        $this->assertMatchesRegularExpression("#event\\.key\\.toLowerCase\\(\\) === 'k'#", $js);

        $f = $this->fixture();
        $html = $this->actingAs($f->owner)->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('data-dsr-shortcut', $html, 'dot-search.js would bind Ctrl K to the pill and fight the palette');
    }

    public function test_the_header_is_one_row_on_a_phone_and_keeps_the_sidebar_under_it(): void
    {
        $css = file_get_contents(resource_path('css/job-card.css'));
        $this->assertStringContainsString('--topbar-h: var(--dot-header-offset', $css, 'the page offsets follow the shared header height');
        $this->assertStringContainsString('inset: var(--topbar-h) auto 0 0', $css, 'the desktop sidebar starts under the header');
        $this->assertStringContainsString('.sidebar[data-dh-open]', $css);
        $this->assertStringContainsString('--dh-bg: var(--ground)', $css, 'the header takes the app tokens');
    }
}
