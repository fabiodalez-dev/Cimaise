#!/bin/bash
#
# Clean PhotoCMS for fresh reinstallation
#
# This script removes:
# - All media variants in public/media/
# - All originals in storage/originals/
# - Database (database.sqlite)
# - Environment config (.env)
# - Cache, logs, and temp files
#
# Usage: bash bin/dev/clean_for_reinstall.sh [--force]
#
# Options:
#   --force    Skip confirmation prompt
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory and project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

cd "$ROOT_DIR"

echo ""
echo -e "${YELLOW}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${YELLOW}║           PhotoCMS - Clean for Reinstallation                  ║${NC}"
echo -e "${YELLOW}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Check for --force flag
FORCE=false
if [[ "$1" == "--force" ]]; then
    FORCE=true
fi

# Show what will be deleted
echo -e "${RED}This will DELETE:${NC}"
echo "  - All files in public/media/ (except .gitkeep, .htaccess)"
echo "  - All files in storage/originals/ (except .gitkeep, .htaccess)"
echo "  - database/database.sqlite"
echo "  - .env file"
echo "  - storage/cache/, storage/logs/, storage/tmp/ contents"
echo ""

# Confirmation prompt (unless --force)
if [[ "$FORCE" != true ]]; then
    read -p "Are you sure you want to proceed? (y/N) " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${YELLOW}Aborted.${NC}"
        exit 0
    fi
fi

echo ""
echo -e "${GREEN}Starting cleanup...${NC}"
echo ""

# 1. Clean media variants
echo "🗑️  Cleaning public/media/..."
find public/media -type f ! -name '.gitkeep' ! -name '.htaccess' -delete 2>/dev/null || true
find public/media -type d -empty -delete 2>/dev/null || true
echo "   ✓ Media variants removed"

# 2. Clean storage originals
echo "🗑️  Cleaning storage/originals/..."
find storage/originals -type f ! -name '.gitkeep' ! -name '.htaccess' -delete 2>/dev/null || true
echo "   ✓ Original files removed"

# 3. Delete database
echo "🗑️  Removing database..."
rm -f database/database.sqlite
echo "   ✓ Database removed"

# 4. Delete .env
echo "🗑️  Removing .env..."
rm -f .env
echo "   ✓ Environment config removed"

# 5. Clean cache, logs, tmp
echo "🗑️  Cleaning cache and temp files..."
rm -rf storage/cache/* 2>/dev/null || true
rm -rf storage/logs/* 2>/dev/null || true
rm -rf storage/tmp/* 2>/dev/null || true
echo "   ✓ Cache and temp files removed"

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║                    Cleanup Complete!                           ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo "To reinstall, start the PHP server and navigate to /install:"
echo ""
echo "  php -S localhost:8000 -t public public/router.php"
echo ""
