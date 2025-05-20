# Content Rebrand Tracker

A WordPress plugin for tracking and managing content rebranding across your site.

## Description

Content Rebrand Tracker makes it easy to search for specific terms across your WordPress site and replace them systematically. It scans posts, pages, meta fields, and WordPress options to find all instances of specified terms.

## Features

- **Comprehensive Search**: Search for terms across posts, pages, meta fields, and options
- **Context-Based Filtering**: Filter results by context (posts, meta, options)
- **Pagination**: Navigate through large sets of results easily
- **Term Replacement**: Replace individual occurrences of terms
- **Memory Management**: Clear stored data with a click

## Usage

1. Navigate to the Rebrand Tracker menu in your WordPress admin
2. Enter a search term to find throughout your site
3. Enter a replacement term 
4. Replace terms individually or track replaced items
5. Use the "Clear All Memory" button to reset tracked state

## Development

This plugin uses WordPress core, React, and custom JavaScript. Data is stored both in the WordPress database and browser localStorage.

## Changelog

### 2.4 - May 19, 2025
- Enhanced the "Clear All Memory" button with improved visual feedback
- Added server-side database cleanup for plugin-specific options
- Fixed transient data handling during memory clearing operations

### 2.3
- Initial public release with core functionality
