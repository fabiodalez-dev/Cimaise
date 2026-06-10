#!/bin/bash

################################################################################
# Cimaise - Build Release Script
#
# Creates a distribution-ready release package
# Excludes development files, dependencies, and sensitive data
#
# Usage: ./bin/build-release.sh [--skip-build] [--output DIR]
#
# Options:
#   --skip-build    Skip NPM build step (use existing assets)
#   --output DIR    Output directory for releases (default: ./releases)
#
# Requirements:
#   - jq (for JSON parsing)
#   - rsync
#   - zip
#   - shasum or sha256sum
#
# Author: Fabio D'Alessandro
# License: MIT
################################################################################

set -e  # Exit on error
set -u  # Exit on undefined variable

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default options
SKIP_BUILD=false
OUTPUT_DIR="releases"

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --skip-build)
            SKIP_BUILD=true
            shift
            ;;
        --output)
            OUTPUT_DIR="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [--skip-build] [--output DIR]"
            echo ""
            echo "Options:"
            echo "  --skip-build    Skip NPM build step"
            echo "  --output DIR    Output directory (default: ./releases)"
            echo "  -h, --help      Show this help message"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

################################################################################
# Functions
################################################################################

log_info() {
    echo -e "${BLUE}ℹ${NC} $1"
}

log_success() {
    echo -e "${GREEN}✓${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

log_error() {
    echo -e "${RED}✗${NC} $1"
}

check_requirements() {
    log_info "Checking requirements..."

    local missing_deps=()

    if ! command -v jq &> /dev/null; then
        missing_deps+=("jq")
    fi

    if ! command -v rsync &> /dev/null; then
        missing_deps+=("rsync")
    fi

    if ! command -v zip &> /dev/null; then
        missing_deps+=("zip")
    fi

    # Required by verify_zip_package (unzip -Z / unzip -q)
    if ! command -v unzip &> /dev/null; then
        missing_deps+=("unzip")
    fi

    if ! command -v shasum &> /dev/null && ! command -v sha256sum &> /dev/null; then
        missing_deps+=("shasum or sha256sum")
    fi

    if [ ${#missing_deps[@]} -gt 0 ]; then
        log_error "Missing required dependencies: ${missing_deps[*]}"
        echo ""
        echo "Install missing dependencies:"
        echo "  macOS:   brew install jq rsync unzip"
        echo "  Ubuntu:  sudo apt-get install jq rsync zip unzip"
        exit 1
    fi

    log_success "All requirements met"
}

# Verify package doesn't contain unwanted files
verify_package_contents() {
    local package_dir=$1
    local has_errors=false

    log_info "Verifying package contents..."

    # ===========================================
    # FORBIDDEN ROOT-LEVEL DIRECTORIES
    # These should not exist at the root of the package
    # ===========================================
    local forbidden_root_dirs=(
        ".git"
        ".gemini"
        ".qoder"
        ".claude"
        ".vscode"
        ".idea"
        ".cursor"
        ".github"
        "node_modules"
        "tests"
        "docs"
        "releases"
        "build"
    )

    for dir in "${forbidden_root_dirs[@]}"; do
        if [ -d "${package_dir}/${dir}" ]; then
            log_error "Package contains forbidden root directory: $dir/"
            has_errors=true
        fi
    done

    # ===========================================
    # FORBIDDEN FILES (exact names)
    # ===========================================
    local forbidden_files=(
        ".env"
        ".gitignore"
        ".gitattributes"
        ".installed"
        "config.local.php"
        "CLAUDE.md"
        "claude.md"
        "updater.md"
        "todo.md"
        "CHANGELOG.md"
        ".distignore"
        ".rsync-filter"
    )

    for file in "${forbidden_files[@]}"; do
        if find "$package_dir" -type f -name "$file" 2>/dev/null | grep -q .; then
            log_error "Package contains forbidden file: $file"
            has_errors=true
        fi
    done

    # ===========================================
    # FORBIDDEN PATTERNS (glob matching)
    # ===========================================
    local forbidden_globs=(
        "*.log"
        "*.tmp"
        "*.cache"
        "*.bak"
        "*.backup"
        "*.zip"
        "*.tar.gz"
        "clean-*.sh"
        "fix-*.php"
        "debug_*.php"
        "test_*.php"
    )

    for glob in "${forbidden_globs[@]}"; do
        if find "$package_dir" -type f -name "$glob" 2>/dev/null | grep -q .; then
            log_error "Package contains forbidden file pattern: $glob"
            has_errors=true
        fi
    done

    # Files that MUST be in the package
    local required_files=(
        "index.php"
        ".htaccess"
        "public/index.php"
        "public/.htaccess"
        "composer.json"
        "version.json"
        ".env.example"
        "README.md"
        "vendor/autoload.php"
        "app/Support/Updater.php"
        "bin/console"
        "database/schema.sqlite.sql"
        "database/schema.mysql.sql"
        "database/template.sqlite"
        "public/assets/.vite/manifest.json"
    )

    for file in "${required_files[@]}"; do
        if [ ! -f "${package_dir}/${file}" ]; then
            log_error "Package missing required file: $file"
            has_errors=true
        fi
    done

    if [ "$has_errors" = true ]; then
        log_error "Package verification failed!"
        return 1
    fi

    log_success "Package contents verified"
    return 0
}

# Return 0 (true) if $1 > $2 using PHP version_compare semantics when available.
# Fallback to sort -V (GNU/BSD) when PHP is missing.
version_gt() {
    if command -v php &> /dev/null; then
        php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"
    else
        [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$1" ]
    fi
}

# ==============================================================================
# Deep verification of the FINAL ZIP (Pinakes "Step 5.5" doctrine).
# Extracts the archive to a temp dir and verifies what will actually be
# installed on production. Any failure deletes the ZIP and aborts the build.
# ==============================================================================
verify_zip_package() {
    local zip_file=$1
    local version=$2
    local package_name="cimaise-v${version}"
    local has_errors=false

    log_info "Verifying final ZIP package (deep checks)..."

    if [ ! -f "$zip_file" ]; then
        log_error "ZIP file not found: $zip_file"
        return 1
    fi

    # ---------------------------------------------------------------
    # 0) NO symlinks in the archive.
    # PHP's ZipArchive extracts symlink entries as small junk text files
    # (Pinakes lesson: 22-byte broken "files" instead of real content).
    # ---------------------------------------------------------------
    local symlinks
    symlinks=$(unzip -Z "$zip_file" 2>/dev/null | awk '$1 ~ /^l/ {print $NF}')
    if [ -n "$symlinks" ]; then
        log_error "ZIP contains symlinks (ZipArchive would extract them as junk files):"
        echo "$symlinks" | head -20
        has_errors=true
    fi

    local verify_dir
    verify_dir=$(mktemp -d "${TMPDIR:-/tmp}/cimaise-zip-verify.XXXXXX")

    if ! unzip -q "$zip_file" -d "$verify_dir"; then
        log_error "Cannot extract ZIP for verification"
        rm -rf "$verify_dir"
        rm -f "$zip_file" "${zip_file}.sha256"
        return 1
    fi

    local pkg="${verify_dir}/${package_name}"
    if [ ! -d "$pkg" ]; then
        log_error "ZIP does not contain expected root directory: ${package_name}/"
        rm -rf "$verify_dir"
        rm -f "$zip_file" "${zip_file}.sha256"
        return 1
    fi

    # ---------------------------------------------------------------
    # 1) version.json exists and matches the release version
    # ---------------------------------------------------------------
    if [ ! -f "$pkg/version.json" ]; then
        log_error "Package missing version.json"
        has_errors=true
    else
        local pkg_version
        pkg_version=$(jq -r '.version' "$pkg/version.json" 2>/dev/null || echo "")
        if [ "$pkg_version" != "$version" ]; then
            log_error "version.json in package ($pkg_version) != release version ($version)"
            has_errors=true
        fi
    fi

    # ---------------------------------------------------------------
    # 2) Required files (anything missing = broken install)
    # ---------------------------------------------------------------
    local required_files=(
        "index.php"
        ".htaccess"
        "public/index.php"
        "public/.htaccess"
        "app/Support/Updater.php"
        "vendor/autoload.php"
        "bin/console"
        "database/schema.sqlite.sql"
        "database/schema.mysql.sql"
        "database/template.sqlite"
        "public/assets/.vite/manifest.json"
        ".env.example"
    )
    local file
    for file in "${required_files[@]}"; do
        if [ ! -f "${pkg}/${file}" ]; then
            log_error "ZIP missing required file: $file"
            has_errors=true
        fi
    done

    # ---------------------------------------------------------------
    # 3) Dev dependencies must NOT leak into the production autoloader
    # (Pinakes "PHPStan disaster": fatal error on every production site)
    # ---------------------------------------------------------------
    local autoload_leaks
    autoload_leaks=$(grep -lEi 'phpstan|phpunit' "$pkg"/vendor/composer/autoload_*.php 2>/dev/null || true)
    if [ -n "$autoload_leaks" ]; then
        log_error "Dev dependencies leaked into vendor/composer autoloader (run composer install --no-dev):"
        echo "$autoload_leaks"
        has_errors=true
    fi

    # ---------------------------------------------------------------
    # 4) Forbidden directories / files must be ABSENT
    # ---------------------------------------------------------------
    local forbidden_dirs=(
        "tests"
        ".github"
        "node_modules"
        ".git"
        "docs"
        "scripts"
        "releases"
        "build-tmp"
        "vendor/phpstan"
        "vendor/phpunit"
        "vendor/bin"
    )
    local dir
    for dir in "${forbidden_dirs[@]}"; do
        if [ -e "${pkg}/${dir}" ]; then
            log_error "ZIP contains forbidden path: ${dir}"
            has_errors=true
        fi
    done

    if [ -f "${pkg}/.env" ]; then
        log_error "ZIP contains .env (secrets!)"
        has_errors=true
    fi

    # storage/logs must contain nothing beyond the .gitkeep skeleton
    if [ -d "${pkg}/storage/logs" ]; then
        local log_leftovers
        log_leftovers=$(find "${pkg}/storage/logs" -type f ! -name '.gitkeep' ! -name '.htaccess' | head -5)
        if [ -n "$log_leftovers" ]; then
            log_error "ZIP contains storage/logs content beyond skeleton:"
            echo "$log_leftovers"
            has_errors=true
        fi
    fi

    # public/media must contain nothing beyond the skeleton (user uploads!)
    if [ -d "${pkg}/public/media" ]; then
        local media_leftovers
        media_leftovers=$(find "${pkg}/public/media" -type f ! -name '.gitkeep' ! -name '.htaccess' | head -5)
        if [ -n "$media_leftovers" ]; then
            log_error "ZIP contains public/media content beyond skeleton (user uploads leaked):"
            echo "$media_leftovers"
            has_errors=true
        fi
    fi

    # dev SQLite databases must never ship
    local db_leftovers
    db_leftovers=$(find "${pkg}/database" -maxdepth 1 -type f \( -name 'database.sqlite' -o -name '*.sqlite-wal' -o -name '*.sqlite-shm' \) 2>/dev/null | head -5)
    if [ -n "$db_leftovers" ]; then
        log_error "ZIP contains a dev database:"
        echo "$db_leftovers"
        has_errors=true
    fi

    # ---------------------------------------------------------------
    # 5) Migration-version rule (hard Pinakes rule):
    # NO migration may have a version GREATER than the release version.
    # version_compare('1.5.0', '1.4.9', '<=') is false -> the runtime
    # updater silently SKIPS such migrations and functionality goes missing.
    # ---------------------------------------------------------------
    local migration mig_base mig_version
    for migration in "$pkg"/database/migrations/migrate_*.sql; do
        [ -e "$migration" ] || continue
        mig_base=$(basename "$migration")
        mig_version=$(echo "$mig_base" | sed -E 's/^migrate_(.+)_(sqlite|mysql)\.sql$/\1/')
        if [ "$mig_version" = "$mig_base" ]; then
            log_error "Migration file does not match naming convention migrate_<ver>_{sqlite,mysql}.sql: $mig_base"
            has_errors=true
            continue
        fi
        if version_gt "$mig_version" "$version"; then
            log_error "Migration $mig_base has version $mig_version > release version $version (would be silently skipped by the updater)"
            has_errors=true
        fi
    done

    # ---------------------------------------------------------------
    # 6) Size sanity bounds.
    # Reference (measured): v1.4.0 ZIP is ~13 MB (38 MB uncompressed:
    # public/ ~19 MB incl. assets+fonts, vendor ~6.5 MB prod-only,
    # storage seeds ~5 MB, app ~4.4 MB). A package under 8 MB almost
    # certainly lost vendor/ or public/assets; over 100 MB something
    # leaked in (node_modules, media uploads, dev DB...).
    # ---------------------------------------------------------------
    local zip_size_bytes
    zip_size_bytes=$(wc -c < "$zip_file" | tr -d ' ')
    local min_bytes=$((8 * 1024 * 1024))
    local max_bytes=$((100 * 1024 * 1024))
    if [ "$zip_size_bytes" -lt "$min_bytes" ]; then
        log_error "ZIP too small: ${zip_size_bytes} bytes (< 8 MB) - vendor/ or assets probably missing"
        has_errors=true
    fi
    if [ "$zip_size_bytes" -gt "$max_bytes" ]; then
        log_error "ZIP too large: ${zip_size_bytes} bytes (> 100 MB) - dev files probably leaked in"
        has_errors=true
    fi

    rm -rf "$verify_dir"

    if [ "$has_errors" = true ]; then
        log_error "ZIP verification FAILED - deleting broken package"
        rm -f "$zip_file" "${zip_file}.sha256"
        return 1
    fi

    log_success "ZIP package verified (symlinks, required files, autoloader, forbidden paths, migrations, size)"
    return 0
}

get_version() {
    if [ ! -f "version.json" ]; then
        log_error "version.json not found"
        exit 1
    fi

    local version
    version=$(jq -r '.version' version.json)

    if [ -z "$version" ] || [ "$version" == "null" ]; then
        log_error "Could not read version from version.json"
        exit 1
    fi

    echo "$version"
}

verify_filter_file() {
    if [ -f ".rsync-filter" ]; then
        return 0
    elif [ -f ".distignore" ]; then
        log_warning "Using legacy .distignore (consider migrating to .rsync-filter)"
        return 1
    fi
    log_warning "No filter file found - all files will be included"
    return 2
}

build_frontend() {
    if [ "$SKIP_BUILD" = true ]; then
        log_warning "Skipping frontend build (--skip-build flag)"
        return 0
    fi

    # Check if package.json exists in root (for npm assets)
    if [ ! -f "package.json" ]; then
        log_info "No package.json found, skipping npm build"
        return 0
    fi

    # Verify npm is available
    if ! command -v npm &> /dev/null; then
        log_error "npm not found but package.json exists: install Node.js before creating a release"
        exit 1
    fi

    log_info "Building frontend assets..."

    log_info "Installing NPM dependencies..."
    npm ci --silent || npm install --silent

    log_info "Running npm build..."
    if npm run build; then
        log_success "Frontend build completed"
    else
        log_error "npm run build failed: aborting release"
        exit 1
    fi
}

create_release_package() {
    local version=$1
    local temp_dir="build-tmp"
    local package_name="cimaise-v${version}"
    local package_dir="${temp_dir}/${package_name}"

    log_info "Creating release package: ${package_name}"

    # Clean and create temp directory
    rm -rf "$temp_dir"
    mkdir -p "$package_dir"

    # Copy files using filter rules
    log_info "Copying project files..."

    verify_filter_file
    local filter_result=$?

    if [ $filter_result -eq 0 ]; then
        # Use new rsync-filter with proper include/exclude syntax
        rsync -a --filter="merge .rsync-filter" . "$package_dir/"
    elif [ $filter_result -eq 1 ]; then
        # Legacy: use .distignore (may have issues with negations)
        rsync -a --exclude-from=.distignore . "$package_dir/"
    else
        log_error "No filter file found (.rsync-filter or .distignore required)"
        log_error "Creating a release without filtering could include sensitive files"
        rm -rf "$temp_dir"
        exit 1
    fi

    # Verify package contents (no forbidden files, all required files present)
    if ! verify_package_contents "$package_dir"; then
        log_error "Package verification failed - aborting"
        rm -rf "$temp_dir"
        exit 1
    fi

    # Create releases directory
    mkdir -p "$OUTPUT_DIR"

    # Create ZIP archive
    log_info "Creating ZIP archive..."

    cd "$temp_dir"
    zip -r "${package_name}.zip" "$package_name" -q

    # Generate SHA256 checksum
    log_info "Generating checksum..."

    if command -v shasum &> /dev/null; then
        shasum -a 256 "${package_name}.zip" > "${package_name}.zip.sha256"
    else
        sha256sum "${package_name}.zip" > "${package_name}.zip.sha256"
    fi

    # Move to releases directory
    mv "${package_name}.zip" "../${OUTPUT_DIR}/"
    mv "${package_name}.zip.sha256" "../${OUTPUT_DIR}/"

    cd ..

    # Cleanup
    rm -rf "$temp_dir"

    log_success "Release package created: ${OUTPUT_DIR}/${package_name}.zip"
}

generate_release_notes() {
    local version=$1
    local notes_file="${OUTPUT_DIR}/RELEASE_NOTES-v${version}.md"

    log_info "Generating release notes..."

    cat > "$notes_file" << EOF
# Cimaise v${version} - Release Notes

**Release Date:** $(date '+%Y-%m-%d')

## 📦 Package Information

- **Version:** ${version}
- **Package:** cimaise-v${version}.zip
- **Size:** $(du -h "${OUTPUT_DIR}/cimaise-v${version}.zip" | cut -f1)

## 🔐 Checksum Verification

\`\`\`bash
shasum -a 256 -c cimaise-v${version}.zip.sha256
\`\`\`

Expected SHA256:
\`\`\`
$(cat "${OUTPUT_DIR}/cimaise-v${version}.zip.sha256")
\`\`\`

## 📋 Installation

1. Extract archive:
   \`\`\`bash
   unzip cimaise-v${version}.zip
   cd cimaise-v${version}
   \`\`\`

2. Configure environment:
   \`\`\`bash
   cp .env.example .env
   # Edit .env with your settings
   \`\`\`

3. Run web installer:
   - Navigate to http://yourdomain.com
   - Follow installation wizard

4. *(Optional)* Refresh Composer dependencies if customizing:
   \`\`\`bash
   composer install --no-dev --optimize-autoloader
   \`\`\`

## 📚 Documentation

- [README.md](README.md) - Complete documentation
- [Installation Guide](#installation)
- [Configuration Guide](#configuration)

## 🆘 Support

For issues and support, visit:
- GitHub Issues: https://github.com/fabiodalez-dev/cimaise/issues

---

Generated on $(date '+%Y-%m-%d %H:%M:%S')
EOF

    log_success "Release notes created: $notes_file"
}

print_summary() {
    local version=$1
    local zip_file="${OUTPUT_DIR}/cimaise-v${version}.zip"
    local zip_size
    zip_size=$(du -h "$zip_file" | cut -f1)
    local checksum
    checksum=$(cut -d' ' -f1 "${OUTPUT_DIR}/cimaise-v${version}.zip.sha256")

    echo ""
    echo "=================================="
    echo -e "${GREEN}✓ Release Build Successful${NC}"
    echo "=================================="
    echo ""
    echo "Version:      v${version}"
    echo "Package:      ${zip_file}"
    echo "Size:         ${zip_size}"
    echo "Checksum:     ${checksum:0:16}..."
    echo ""
    echo "Files created:"
    echo "  - ${zip_file}"
    echo "  - ${zip_file}.sha256"
    echo "  - ${OUTPUT_DIR}/RELEASE_NOTES-v${version}.md"
    echo ""
    echo "Next steps:"
    echo "  1. Test the release package locally"
    echo "  2. Create GitHub release: git tag v${version} && git push --tags"
    echo "  3. Upload ZIP and checksum to GitHub release"
    echo ""
}

################################################################################
# Main execution
################################################################################

main() {
    echo ""
    echo "╔════════════════════════════════════════╗"
    echo "║    Cimaise - Release Build Script     ║"
    echo "╚════════════════════════════════════════╝"
    echo ""

    # Check requirements
    check_requirements

    # Get version
    local version
    version=$(get_version)
    log_info "Building release for version: v${version}"

    # Build frontend
    build_frontend

    # Create release package
    create_release_package "$version"

    # Deep-verify the final ZIP (deletes it and aborts on any failure)
    if ! verify_zip_package "${OUTPUT_DIR}/cimaise-v${version}.zip" "$version"; then
        log_error "Release build aborted: ZIP verification failed"
        exit 1
    fi

    # Generate release notes
    generate_release_notes "$version"

    # Print summary
    print_summary "$version"
}

# Run main function
main "$@"
