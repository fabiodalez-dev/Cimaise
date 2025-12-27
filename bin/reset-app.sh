#!/bin/bash
#
# Reset Cimaise to a clean state for fresh installation
# Usage: bash bin/reset-app.sh
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo -e "${YELLOW}=== Cimaise Reset Script ===${NC}"
echo "This will delete all user data and reset the app for fresh installation."
echo ""

# Confirmation
read -p "Are you sure you want to continue? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}Aborted.${NC}"
    exit 1
fi

echo ""

# 1. Delete original photos
echo -n "Cleaning storage/originals... "
find "$PROJECT_ROOT/storage/originals" -type f ! -name '.gitkeep' ! -name '.htaccess' -delete 2>/dev/null || true
echo -e "${GREEN}done${NC}"

# 2. Delete media variants
echo -n "Cleaning public/media... "
find "$PROJECT_ROOT/public/media" -type f ! -name '.gitkeep' ! -name '.htaccess' -delete 2>/dev/null || true
find "$PROJECT_ROOT/public/media" -mindepth 1 -type d -empty -delete 2>/dev/null || true
echo -e "${GREEN}done${NC}"

# 3. Delete database files
echo -n "Removing database files... "
rm -f "$PROJECT_ROOT/storage/database.sqlite" 2>/dev/null || true
rm -f "$PROJECT_ROOT/database/database.sqlite" 2>/dev/null || true
rm -f "$PROJECT_ROOT/database/cimaise.sqlite" 2>/dev/null || true
echo -e "${GREEN}done${NC}"

# 4. Delete logos and favicons
echo -n "Removing logos and favicons... "
rm -f "$PROJECT_ROOT/public/logo."* 2>/dev/null || true
rm -f "$PROJECT_ROOT/public/favicon"* 2>/dev/null || true
rm -f "$PROJECT_ROOT/public/apple-touch-icon"*.png 2>/dev/null || true
rm -f "$PROJECT_ROOT/public/android-chrome-"*.png 2>/dev/null || true
rm -f "$PROJECT_ROOT/public/mstile-"*.png 2>/dev/null || true
echo -e "${GREEN}done${NC}"

# 5. Clean cache, logs, and temp files
echo -n "Cleaning cache and temp files... "
find "$PROJECT_ROOT/storage/cache" -type f ! -name '.gitkeep' ! -name 'lensfun.json' -delete 2>/dev/null || true
find "$PROJECT_ROOT/storage/tmp" -type f ! -name '.gitkeep' -delete 2>/dev/null || true
find "$PROJECT_ROOT/storage/logs" -type f ! -name '.gitkeep' -delete 2>/dev/null || true
find "$PROJECT_ROOT/storage/rate_limits" -type f ! -name '.gitkeep' -delete 2>/dev/null || true
echo -e "${GREEN}done${NC}"

# 6. Delete .env file
echo -n "Removing .env... "
rm -f "$PROJECT_ROOT/.env" 2>/dev/null || true
echo -e "${GREEN}done${NC}"

# 7. Remove maintenance mode flag
echo -n "Removing maintenance flag... "
rm -f "$PROJECT_ROOT/storage/.maintenance" 2>/dev/null || true
echo -e "${GREEN}done${NC}"

echo ""
echo -e "${GREEN}=== Reset Complete ===${NC}"
echo "The application is now ready for fresh installation."
echo "Visit /install in your browser to start the installer."
