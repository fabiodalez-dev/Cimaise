#!/bin/bash
# sync-demo.sh - Synchronize demo folder with main app while preserving demo features
#
# This script updates the demo folder from the main app, applying demo-specific
# patches to maintain demo functionality (template switching, password protection, etc.)
#
# Usage: ./bin/sync-demo.sh [--dry-run]

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DEMO_ROOT="$PROJECT_ROOT/demo"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

DRY_RUN=false
if [[ "$1" == "--dry-run" ]]; then
    DRY_RUN=true
    echo -e "${YELLOW}DRY RUN MODE - No changes will be made${NC}"
fi

log() { echo -e "${GREEN}[SYNC]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Files and directories to sync (from main app to demo)
SYNC_DIRS=(
    "app/Config"
    "app/Controllers"
    "app/Extensions"
    "app/Installer"
    "app/Middlewares"
    "app/Repositories"
    "app/Services"
    "app/Support"
    "app/Tasks"
    "app/Views"
    "bin"
    "plugins"
    "resources"
    "storage/translations"
)

SYNC_FILES=(
    "public/index.php"
    "public/router.php"
    "public/.htaccess"
    "public/sw.js"
    "public/offline.html"
    "composer.json"
    "composer.lock"
    "package.json"
    "package-lock.json"
    "vite.config.js"
    "tailwind.config.js"
    "postcss.config.js"
)


# ==============================================================================
# SYNC FUNCTIONS
# ==============================================================================

sync_directories() {
    log "Syncing directories..."

    for dir in "${SYNC_DIRS[@]}"; do
        src="$PROJECT_ROOT/$dir"
        dst="$DEMO_ROOT/$dir"

        if [[ -d "$src" ]]; then
            log "  Syncing $dir/"
            if [[ "$DRY_RUN" == false ]]; then
                mkdir -p "$(dirname "$dst")"
                rsync -a --delete \
                    --exclude='.DS_Store' \
                    --exclude='CLAUDE.md' \
                    --exclude='*.log' \
                    "$src/" "$dst/"
            fi
        else
            warn "  Source directory not found: $dir"
        fi
    done
}

sync_files() {
    log "Syncing individual files..."

    for file in "${SYNC_FILES[@]}"; do
        src="$PROJECT_ROOT/$file"
        dst="$DEMO_ROOT/$file"

        if [[ -f "$src" ]]; then
            log "  Syncing $file"
            if [[ "$DRY_RUN" == false ]]; then
                mkdir -p "$(dirname "$dst")"
                cp "$src" "$dst"
            fi
        else
            warn "  Source file not found: $file"
        fi
    done
}

sync_public_assets() {
    log "Syncing public assets..."

    if [[ "$DRY_RUN" == false ]]; then
        # Sync assets folder (CSS/JS)
        rsync -a --delete \
            --exclude='.DS_Store' \
            "$PROJECT_ROOT/public/assets/" "$DEMO_ROOT/public/assets/"

        # Sync fonts
        rsync -a --delete \
            --exclude='.DS_Store' \
            "$PROJECT_ROOT/public/fonts/" "$DEMO_ROOT/public/fonts/"

        # Sync favicons (if they exist)
        for favicon in favicon.ico favicon-*.png apple-touch-icon.png android-chrome-*.png site.webmanifest; do
            if [[ -f "$PROJECT_ROOT/public/$favicon" ]]; then
                cp "$PROJECT_ROOT/public/$favicon" "$DEMO_ROOT/public/"
            fi
        done
    fi
}

# ==============================================================================
# DEMO PATCHES - Apply demo-specific modifications
# ==============================================================================

patch_index_php() {
    log "Applying demo patch: public/index.php"

    local file="$DEMO_ROOT/public/index.php"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # Insert DEMO_MODE constant after declare(strict_types=1);
    sed -i.bak 's/^declare(strict_types=1);$/declare(strict_types=1);\
\
\/\/ DEMO MODE: This is a demo instance with fixed credentials and restricted features\
define('\''DEMO_MODE'\'', true);/' "$file"
    rm -f "$file.bak"

    # Add demo Twig globals before $app->run();
    # Find the line with "$app->run();" and insert before it
    local demo_globals='
// DEMO MODE: Expose demo status and credentials for templates
if (defined('\''DEMO_MODE'\'') \&\& DEMO_MODE) {
    $twig->getEnvironment()->addGlobal('\''is_demo'\'', true);
    $twig->getEnvironment()->addGlobal('\''demo_credentials'\'', '\''demo@cimaise.local / password123'\'');
}
'
    # Use awk to insert before $app->run()
    awk -v insert="$demo_globals" '
        /^\$app->run\(\);/ { print insert }
        { print }
    ' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

patch_auth_controller() {
    log "Applying demo patch: AuthController.php"

    local file="$DEMO_ROOT/app/Controllers/Admin/AuthController.php"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # Add demo mode password protection block at the start of changePassword method
    local demo_block='        // DEMO MODE: Block password change
        if (defined('\''DEMO_MODE'\'') \&\& DEMO_MODE) {
            $_SESSION['\''flash'\''][] = [
                '\''type'\'' => '\''warning'\'',
                '\''message'\'' => '\''Il cambio password è disabilitato in modalità demo. Credenziali: demo@cimaise.local / password123'\''
            ];
            return $response->withHeader('\''Location'\'', $_SERVER['\''HTTP_REFERER'\''] ?? $this->redirect('\''/admin'\''))->withStatus(302);
        }

'
    # Insert after "public function changePassword" opening brace
    awk -v insert="$demo_block" '
        /public function changePassword\(/ { found=1 }
        found && /\{/ && !done { print; print insert; done=1; next }
        { print }
    ' "$file" > "$file.tmp" && mv "$file.tmp" "$file"

    # Also add is_demo to showLogin render
    sed -i.bak "s/'csrf' => \$_SESSION\['csrf'\] ?? ''/'csrf' => \$_SESSION['csrf'] ?? '',\n            'is_demo' => defined('DEMO_MODE') \&\& DEMO_MODE/" "$file"
    rm -f "$file.bak"
}

patch_page_controller() {
    log "Applying demo patch: PageController.php"

    local file="$DEMO_ROOT/app/Controllers/Frontend/PageController.php"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # Add template override logic after home template is fetched
    local template_override='
        // DEMO MODE: Allow template override via ?template= query parameter
        $templateOverride = $request->getQueryParams()['\''template'\''] ?? null;
        $validTemplates = ['\''classic'\'', '\''modern'\'', '\''parallax'\'', '\''masonry'\'', '\''snap'\'', '\''gallery'\''];
        if ($templateOverride && in_array($templateOverride, $validTemplates, true)) {
            $homeTemplate = $templateOverride;
        }
'
    # Insert after "$homeTemplate = " line in home() method
    awk -v insert="$template_override" '
        /\$homeTemplate = .*get\(.*home\.template/ { print; print insert; next }
        { print }
    ' "$file" > "$file.tmp" && mv "$file.tmp" "$file"
}

patch_admin_layout() {
    log "Applying demo patch: admin/_layout.twig"

    local file="$DEMO_ROOT/app/Views/admin/_layout.twig"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # Add demo banner after <body> tag
    local demo_banner='
  {# DEMO MODE: Demo banner #}
  {% if is_demo is defined and is_demo %}
  <div class="bg-white text-gray-600 text-center py-2 text-sm font-medium fixed w-full z-50 top-0 border-b border-gray-200">
    <i class="fas fa-flask mr-2 text-gray-400"></i>
    DEMO MODE - Credenziali: <strong class="text-gray-900">{{ demo_credentials|default('\''demo@cimaise.local / password123'\'') }}</strong> - Reset automatico ogni 24 ore
  </div>
  <style nonce="{{ csp_nonce() }}">
    /* Shift everything down to make room for demo banner */
    body { padding-top: 38px; }
    nav.fixed.top-0 { top: 38px !important; }
    aside.fixed.top-0 { top: 38px !important; }
  </style>
  {% endif %}
'
    # Insert after opening <body> tag
    sed -i.bak "s/<body class=\"bg-gray-50\">/<body class=\"bg-gray-50\">\n$demo_banner/" "$file"
    rm -f "$file.bak"
}

patch_frontend_layout() {
    log "Applying demo patch: frontend/_layout.twig"

    local file="$DEMO_ROOT/app/Views/frontend/_layout.twig"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # Add demo template menu include in navigation
    # This needs to be added in the header navigation area
    # Find the nav area and add the demo menu

    # Add near the end of the header navigation (before closing </nav> or similar)
    sed -i.bak 's|</header>|{% if is_demo is defined and is_demo %}\n                        {% include '\''frontend/_demo_template_menu.twig'\'' %}\n                    {% endif %}\n</header>|' "$file"
    rm -f "$file.bak"
}

patch_login_template() {
    log "Applying demo patch: admin/login.twig"

    local file="$DEMO_ROOT/app/Views/admin/login.twig"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # Add demo credentials box before the error display
    local demo_box='
    {# DEMO MODE: Show login credentials #}
    {% if is_demo is defined and is_demo %}
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
      <div class="flex items-start gap-3">
        <div class="flex-shrink-0">
          <i class="fas fa-flask text-amber-600 text-lg"></i>
        </div>
        <div class="flex-1">
          <h3 class="font-semibold text-amber-900 mb-2">Demo Mode</h3>
          <p class="text-sm text-amber-800 mb-3">Use these credentials to explore the admin panel:</p>
          <div class="bg-white/60 rounded-lg p-3 space-y-2">
            <div class="flex items-center gap-2 text-sm">
              <span class="text-amber-700 font-medium w-20">Email:</span>
              <code class="bg-amber-100 px-2 py-0.5 rounded text-amber-900 font-mono">demo@cimaise.local</code>
            </div>
            <div class="flex items-center gap-2 text-sm">
              <span class="text-amber-700 font-medium w-20">Password:</span>
              <code class="bg-amber-100 px-2 py-0.5 rounded text-amber-900 font-mono">password123</code>
            </div>
          </div>
          <p class="text-xs text-amber-600 mt-2">
            <i class="fas fa-sync-alt mr-1"></i>
            This demo resets automatically every 24 hours
          </p>
        </div>
      </div>
    </div>
    {% endif %}

'
    # Insert before {% if error %}
    sed -i.bak "s/{% if error %}/$demo_box{% if error %}/" "$file"
    rm -f "$file.bak"
}

restore_demo_template_menu() {
    log "Restoring demo-only file: _demo_template_menu.twig"

    local file="$DEMO_ROOT/app/Views/frontend/_demo_template_menu.twig"
    if [[ "$DRY_RUN" == true ]]; then return; fi

    # This file is demo-only, create it if it doesn't exist
    cat > "$file" << 'TWIG_EOF'
{# Demo Mode: Home Template Switcher Dropdown #}
<style nonce="{{ csp_nonce() }}">
#demo-home-templates .demo-dropdown {
    opacity: 0;
    visibility: hidden;
    transform: translateY(4px);
    transition: all 0.2s ease;
}
#demo-home-templates:hover .demo-dropdown {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}
#demo-home-templates:hover .demo-chevron {
    transform: rotate(180deg);
}
.demo-menu-item:hover {
    background-color: #f3f4f6;
}
.dark .demo-menu-item:hover {
    background-color: #404040;
}
</style>
<div class="relative" id="demo-home-templates">
    <button class="flex items-center gap-2 text-sm font-medium hover:text-gray-600 dark:hover:text-gray-300 py-2 transition-colors">
        <i class="fas fa-palette"></i>
        <span>Home Templates</span>
        <i class="fas fa-chevron-down text-xs transition-transform demo-chevron"></i>
    </button>
    <div class="demo-dropdown absolute left-0 top-full mt-1 w-64 bg-white dark:bg-neutral-800 border border-gray-200 dark:border-neutral-700 shadow-xl rounded-xl z-50">
        <div class="p-3">
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400 font-semibold mb-2 px-3">Home Templates</div>
            <ul class="space-y-1">
                <li>
                    <a href="{{ base_path }}/?template=classic" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-images text-gray-600 dark:text-gray-400 w-5 text-center"></i>
                        <div>
                            <div class="font-medium">Classic</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Hero + masonry + carousel</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=modern" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-th-large text-indigo-500 w-5 text-center"></i>
                        <div>
                            <div class="font-medium">Modern</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Sidebar + grid + smooth scroll</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=parallax" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-layer-group text-cyan-500 w-5 text-center"></i>
                        <div>
                            <div class="font-medium">Parallax</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Full-screen parallax effects</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=masonry" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-grip-vertical text-pink-500 w-5 text-center"></i>
                        <div>
                            <div class="font-medium">Pure Masonry</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">True masonry grid layout</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=snap" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-arrows-alt-v text-amber-500 w-5 text-center"></i>
                        <div>
                            <div class="font-medium">Snap Albums</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Full-screen scroll-snap</div>
                        </div>
                    </a>
                </li>
                <li>
                    <a href="{{ base_path }}/?template=gallery" class="demo-menu-item flex items-center gap-3 px-3 py-2.5 text-sm rounded-lg transition-colors">
                        <i class="fas fa-grip-horizontal text-green-500 w-5 text-center"></i>
                        <div>
                            <div class="font-medium">Gallery Wall</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Horizontal scroll animation</div>
                        </div>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
TWIG_EOF
}

# ==============================================================================
# MAIN
# ==============================================================================

main() {
    log "Starting demo sync..."
    log "Project root: $PROJECT_ROOT"
    log "Demo root: $DEMO_ROOT"
    echo ""

    # Ensure demo directory exists
    if [[ ! -d "$DEMO_ROOT" ]]; then
        error "Demo directory not found: $DEMO_ROOT"
        exit 1
    fi

    # Step 1: Sync directories and files
    sync_directories
    sync_files
    sync_public_assets

    echo ""
    log "Applying demo-specific patches..."

    # Step 2: Apply demo patches
    patch_index_php
    patch_auth_controller
    patch_page_controller
    patch_admin_layout
    patch_frontend_layout
    patch_login_template
    restore_demo_template_menu

    echo ""
    log "Demo sync complete!"

    if [[ "$DRY_RUN" == true ]]; then
        echo ""
        warn "DRY RUN - No actual changes were made"
    fi
}

main "$@"
