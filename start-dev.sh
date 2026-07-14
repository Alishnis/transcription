#!/bin/bash
# Development server startup script

PORT=${1:-8000}
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

echo "🚀 Starting transcription bot development server..."
echo "📍 Listen on http://localhost:$PORT/"
echo "📁 Document root: $SCRIPT_DIR/public"
echo "🛑 Press Ctrl+C to stop"
echo ""

cd "$SCRIPT_DIR"
php -c php.ini -S localhost:$PORT -t public public/index.php
