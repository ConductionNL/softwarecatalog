# Software Catalogus

[![License: EUPL](https://img.shields.io/badge/License-EUPL-blue.svg)](https://opensource.org/licenses/EUPL)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-Compatible-brightgreen)](https://nextcloud.com/)
[![Version](https://img.shields.io/badge/version-0.1.1-blue)]()

The **Software Catalogus** is a Nextcloud app that provides a powerful framework for managing and synchronizing software catalogs in an open data ecosystem. This app enables organizations to keep their software data up-to-date, facilitates collaboration, and promotes transparency through open data practices.

## Features

- 🔄 **Synchronize Software Data**: Automatically synchronize your software data across multiple catalogs.
- 📡 **Automatic Publication**: Publish and update software catalog information seamlessly.
- 🆓 **Open Source**: Licensed under the [EUPL](https://opensource.org/licenses/EUPL).

## Requirements

- PHP 8.0 or higher
- PostgreSQL 10+, SQLite, or MySQL 8.0+
- Nextcloud version 28 to 30
- System Cron is required for the app to function properly

## Installation

To install the Software Catalogus app, follow these steps:

1. **Download the App:**
   Download the latest release from the [GitHub repository](https://github.com/ConductionNL/SoftwareCatalogus/releases).

2. **Upload the App:**
   Upload the app to the `apps` directory of your Nextcloud installation.

3. **Enable the App:**
   Go to the "Apps" section in your Nextcloud instance and enable the **Software Catalogus** app.

4. **Configure System Cron:**
   Ensure that the System Cron is properly configured on your server to allow the app to function optimally.

## Usage

Once installed, the Software Catalogus app will appear in your Nextcloud sidebar as **Catalogs**. You can:

- **Manage Software Data:** Use the interface to add, update, and delete software entries
