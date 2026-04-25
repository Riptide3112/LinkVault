# LinkVault - URL Shortener with Advanced Analytics

A production-ready URL shortening platform with real-time click tracking, geographic analytics, user management, and an administrative dashboard. Built with PHP 8.x, MySQL/MariaDB, and vanilla JavaScript.

## Features

### Core Functionality
- **URL Shortening**: Convert long URLs into short, memorable links
- **Expiration Control**: Set links to expire after 1, 7, 30, or 90 days, or never expire
- **QR Code Generation**: Automatic QR code for every shortened link
- **Click Analytics**: Track total clicks, unique visitors, and click timing
- **Geographic Statistics**: View top countries and cities where your links were clicked
- **Traffic Sources**: Identify referrers (Google, Facebook, Twitter, Direct, etc.)

### User System
- **Registration & Login**: Secure account creation with email verification preparation
- **Personal Dashboard**: Manage all your links in one place
- **Account Settings**: Change username, password, or delete account
- **Real-time Stats**: View detailed statistics for each of your links

### Admin Panel
- **System Overview**: Global statistics dashboard with activity charts
- **User Management**: View, promote/demote, or delete user accounts
- **Link Moderation**: Browse all links with search and filter capabilities
- **Link Actions**: Preview, suspend (expire immediately), or delete any link
- **Live Search**: Real-time filtering of users and links

### Technical Features
- **Responsive Design**: Mobile-first approach, works on all devices
- **Dark Theme**: Modern glassmorphism UI with dark color scheme
- **Auto-refresh**: Latest links section updates every 30 seconds without page reload
- **Toast Notifications**: Non-intrusive feedback system for user actions
- **Modal Confirmation**: Prevent accidental deletions with confirmation dialogs

## Security Implementation

LinkVault implements multiple layers of security to protect data and prevent abuse:

### CSRF Protection
- Each session receives a cryptographically secure random token (32 bytes / 64 hex chars)
- All POST requests must include a valid CSRF token
- Tokens are verified using timing-safe comparison (`hash_equals`)
- Invalid tokens result in HTTP 403 error

```php
// Token generation uses random_bytes(32) - cryptographically secure
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// Verification uses hash_equals to prevent timing attacks
hash_equals(csrf_token(), $token);
