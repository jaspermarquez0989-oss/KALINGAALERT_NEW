# KalingaAlert

KalingaAlert is a location-targeted hazard advisory system for Bongabong, Oriental Mindoro. The Laravel backend and React application are separate projects in `backend/` and `frontend/`. All browser-facing pages are React; Laravel's page URLs redirect to the configured frontend, and application data is provided by the versioned API.

## Local setup

Requirements: PHP 8.3+, Composer, Node.js/npm, and SQLite or MySQL/MariaDB.

### Backend

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Set `FRONTEND_URL=http://localhost:5173` in `backend/.env` to allow the local frontend origin. The default SQLite database is `backend/database/database.sqlite`. If PHP cannot verify PAGASA's TLS certificate, set `PAGASA_CA_BUNDLE` to a trusted CA bundle; do not disable certificate verification.

### Frontend

In a second terminal:

```powershell
cd frontend
Copy-Item .env.example .env
npm install
npm run dev -- --host localhost
```

The frontend defaults to `http://127.0.0.1:8000/api/v1`; set `VITE_API_BASE_URL` in `frontend/.env` if the backend uses another URL. Open the Vite URL shown in the terminal. For production, build the frontend with `npm run build` in `frontend/`, deploy its `dist/` output separately, and set the backend's `FRONTEND_URL` and frontend's `VITE_API_BASE_URL` to their deployed origins.

The backend exposes the versioned API under `/api/v1` and validates browser cross-origin requests against `FRONTEND_URL`. The React app stores its short-lived bearer token in the current browser tab's `sessionStorage`. There are no Blade UI templates or backend frontend-build steps.

To run tests or the scheduled PAGASA import, use the backend project:

```powershell
cd backend
php artisan test
php artisan schedule:work
```

In production, configure Laravel's scheduler to run `php artisan schedule:run` every minute.

## Roles and scope

- **System administrator:** create staff accounts, assign staff roles, manage access, and review audit history.
- **MDRRMO personnel:** manage vulnerable areas, review source records, prepare/approve alerts, and close alerts.
- **BFP / fire information officer:** submit BFP-confirmed fire information for review.
- **Authorized alert operator:** prepare alerts and publish approved advisories.
- **Community resident:** maintain their barangay/sitio and view alerts matched to their registered location.

Source verification and alert approval require a different staff account from the person who submitted or drafted the record. Alerts are delivered inside the web application and retain recipient delivery/read timestamps.

This implementation does not predict hazards or fire locations, automatically publish imported PAGASA warnings, connect to BFP data feeds, send SMS or push notifications, collect resident incident reports, request rescue assistance, dispatch responders, plan evacuations, or replace official warnings and emergency instructions. PAGASA imports depend on its public warning pages being reachable and retain the page text and source link for authorized staff verification.

The map uses Leaflet and OpenStreetMap tiles and needs an internet connection. Public maps contain risk-area polygons only; individual resident locations are not plotted.
