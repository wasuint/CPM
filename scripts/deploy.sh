#!/bin/bash

# Claude Project Manager - Deployment Script
# Automates setup after git pull on new servers

set -e  # Exit on any error

echo "🚀 Claude Project Manager - Deployment Setup"
echo "=============================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if we're in the right directory
if [[ ! -f "composer.json" ]] || [[ ! -f "bin/claude-project" ]]; then
    print_error "This doesn't appear to be the Claude Project Manager directory"
    print_error "Please run this script from the CPM root directory"
    exit 1
fi

print_status "Starting deployment setup..."

# Check PHP version
print_status "Checking PHP version..."
PHP_VERSION=$(php -r "echo PHP_VERSION;")
PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;")

if [[ $PHP_MAJOR -lt 8 ]] || [[ $PHP_MAJOR -eq 8 && $PHP_MINOR -lt 1 ]]; then
    print_error "PHP 8.1 or higher is required. Current version: $PHP_VERSION"
    exit 1
fi

print_success "PHP version OK: $PHP_VERSION"

# Check if Composer is installed
print_status "Checking Composer..."
if ! command -v composer &> /dev/null; then
    print_error "Composer is not installed. Please install Composer first:"
    print_error "curl -sS https://getcomposer.org/installer | php"
    print_error "sudo mv composer.phar /usr/local/bin/composer"
    exit 1
fi

COMPOSER_VERSION=$(composer --version --no-ansi | cut -d' ' -f3)
print_success "Composer found: $COMPOSER_VERSION"

# Install dependencies
print_status "Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader

if [[ $? -ne 0 ]]; then
    print_error "Composer install failed"
    exit 1
fi

print_success "Dependencies installed successfully"

# Make binaries executable
print_status "Setting up binaries..."
chmod +x bin/claude-project
chmod +x bin/cpm

if [[ -f "vendor/bin/claude-project" ]]; then
    chmod +x vendor/bin/claude-project
fi

print_success "Binaries are now executable"

# This installer deliberately does not modify your shell profile, your PATH or
# /usr/local/bin. It prints the lines to add so you stay in control of your own
# machine. Run them yourself if you want the `cpm` command available globally.
CPM_BIN_DIR="$(pwd)/bin"

# Test installation
print_status "Testing installation..."

# Test direct binary
if ./bin/claude-project --version &> /dev/null; then
    VERSION=$(./bin/claude-project --version)
    print_success "Binary works: $VERSION"
else
    print_error "Binary test failed"
    exit 1
fi

# Test global command if available
if command -v cpm &> /dev/null; then
    print_success "Global 'cpm' command is available"
elif command -v claude-project &> /dev/null; then
    print_success "Global 'claude-project' command is available"
else
    print_warning "No global 'cpm' command on PATH yet"
    print_warning "Use ./bin/claude-project, or apply one of the optional steps below"
fi

# Display usage information
echo ""
echo "🎉 Deployment completed successfully!"
echo ""
echo "🔧 Optional — make 'cpm' available everywhere (run these yourself):"
echo "==========================================================="
echo ""
echo "  Per-user, no root required — add to ~/.bashrc or ~/.zshrc:"
echo ""
echo "      export PATH=\"$CPM_BIN_DIR:\$PATH\""
echo ""
echo "  System-wide, requires root:"
echo ""
echo "      sudo ln -sf $CPM_BIN_DIR/claude-project /usr/local/bin/claude-project"
echo "      sudo ln -sf $CPM_BIN_DIR/cpm /usr/local/bin/cpm"
echo ""

echo "📋 Next Steps:"
echo "============="
echo ""
echo "1. 📁 Navigate to your project directory:"
echo "   cd /path/to/your-project/"
echo ""
echo "2. 🚀 Initialize CPM:"
echo "   cpm start                    # If global command works"
echo "   # OR"
echo "   cpm start                            # Alternative command"
echo ""
echo "3. 📊 Check status:"
echo "   cpm status"
echo ""
echo "4. 📚 Enable monitoring:"
echo "   cpm monitor --daemon"
echo ""
echo "💡 Available Commands:"
echo "   cpm start                    # Initialize project"
echo "   cpm status                   # Check progress"  
echo "   cpm monitor --daemon         # Background monitoring"
echo "   cpm context:digest           # Generate Claude context"
echo "   cpm compact                  # Optimize file sizes"
echo "   cpm diagnostics              # Troubleshoot issues"
echo ""
echo "📖 Documentation:"
echo "   README.md                    # Getting started"
echo "   docs/                        # Full documentation"
echo ""

# Show current directory for reference
echo "📍 CPM Location: $(pwd)"
echo ""

print_success "Deployment setup complete! 🚀"