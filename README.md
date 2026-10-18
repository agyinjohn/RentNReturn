# RentNReturn — Peer-to-Peer Rental Marketplace

A minimal “Airbnb for items” MVP: list items, request to rent, accept/decline, no payments.

## Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB
- Composer
- (Optional) AI API key for generated listing descriptions: **Gemini** (from [aistudio.google.com](https://aistudio.google.com/app/apikey)) or OpenAI

## Setup

1. **Clone and install**
   ```bash
   cd p2p_lend
   composer install
   ```

2. **Environment**
   - Copy `.env.example` to `.env` and set your database credentials and optional AI key (`GEMINI_API_KEY` or `OPENAI_API_KEY`; see `.env.example`).

3. **Database (WAMP + phpMyAdmin)**
   - Start WAMP (MySQL and Apache must be running).
   - Open **phpMyAdmin**: `http://localhost/phpmyadmin` (or the URL shown in the WAMP menu).
   - Create the database (you can keep the default name):
     - Click **New** in the left sidebar (or the “Databases” tab).
     - Database name: `p2p_rent`.
     - Collation: `utf8mb4_unicode_ci` (or leave default).
     - Click **Create**.
   - Import the schema:
     - Click **`p2p_rent`** in the left sidebar to select it.
     - Open the **Import** tab.
     - Click **Choose File** and select the file:  
       `C:\Users\...\p2p_lend\storage\schema.sql`  
       (use the full path to your project’s `storage\schema.sql`).
     - Click **Go** at the bottom. You should see a success message and the tables (`users`, `listings`, `listing_images`, `rental_requests`, `bookings`) under `p2p_rent`.
   - **.env for WAMP:** In your `.env` use the MySQL user you use in phpMyAdmin (often `root` with no password):
     ```
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_DATABASE=p2p_rent
     DB_USERNAME=root
     DB_PASSWORD=
     ```

   **Database (command line)**  
   Alternatively, from a terminal:  
   `mysql -u root -p < storage/schema.sql`

4. **Load env (optional)**  
   If you use a `.env` file, load it before PHP (e.g. with `vlucas/phpdotenv` or by exporting vars). The app reads `getenv('DB_*')`, `getenv('GEMINI_API_KEY')` / `getenv('OPENAI_API_KEY')`, etc. You can also set these in your web server or `php.ini`.

5. **Web root**
   - Point the document root to the `public/` directory.
   - **Apache:** Ensure `mod_rewrite` is enabled; `public/.htaccess` is included.
   - **PHP built-in server:**
     ```bash
     cd public && php -S localhost:8080
     ```
     Then open `http://localhost:8080`.

6. **Uploads**
   - Listing images are stored under `public/uploads/listings/`. The directory is created automatically on first upload.

## Features (Phase 1)

- **Auth:** Sign up, log in, log out. Basic profile: name + contact preference.
- **Listings:** Create with 2–5 images, title, category, condition, daily price, availability (available now or date range). Publish/Unpublish from “My listings”.
- **AI description:** After uploading photos, the app can generate a suggested title and description (Gemini or OpenAI). You get a “Review & Edit” step; only the human-approved text is stored as the listing description. Both `ai_suggested_description` and `final_description` are stored.
- **Browse:** Keyword search, category filter, listing cards, click-through to listing page (gallery, price, owner, availability, rating placeholder).
- **Request to rent:** On a listing, choose start/end date and optional message. Owner sees requests in Dashboard and can Accept or Decline. Accepted requests become Bookings (no payment/delivery in MVP).

## Project structure

```
config/          App and list config (categories, conditions)
public/          Web root (index.php, assets, uploads)
src/             PHP classes
  Controllers/   Auth, Listings, Dashboard, Requests, Api
  Services/      AI image description (Gemini / OpenAI)
templates/       PHP view templates
storage/         Schema SQL only (uploads live under public/)
```

## Configuration

- **Categories / Conditions:** Edit `config/categories.php` and `config/conditions.php`.
- **Upload limits:** `config/app.php` → `upload` (min/max images, size, types).
- **AI:** Set `AI_PROVIDER=gemini` and `GEMINI_API_KEY` (from [AI Studio](https://aistudio.google.com/app/apikey)), or `AI_PROVIDER=openai` and `OPENAI_API_KEY`. If no key is set, the create flow still works; the description step will show a fallback and you type the description manually.

## License

MIT.
