# Quick PostgreSQL Setup for Railway

## Step 1: Add PostgreSQL Database

1. **Go to your Railway project dashboard**
   - Visit [railway.app](https://railway.app)
   - Open your AquaSphere project

2. **Add PostgreSQL Database**
   - Click the **"+ New"** button (top right)
   - Select **"Database"**
   - Choose **"Add PostgreSQL"**
   - Railway will automatically create a PostgreSQL database for you
   - Wait 1-2 minutes for it to provision

## Step 2: Link Database to Your App

1. **Get Database Connection String**
   - Click on the **PostgreSQL** service card (the one you just created)
   - Go to the **"Variables"** tab
   - You'll see `DATABASE_URL` - this is your connection string
   - It looks like: `postgresql://postgres:password@hostname:5432/railway`

2. **Link to Your App Service**
   - Go back to your main application service (the one running PHP)
   - Click on it
   - Go to **"Variables"** tab
   - Railway should **automatically** add `DATABASE_URL` from the linked database
   - If you see `DATABASE_URL` in the variables list, you're good! ✅

## Step 3: Initialize Database

After PostgreSQL is connected, you need to initialize the database tables:

**Option 1: Visit the init endpoint**
```
https://your-app.railway.app/api/init.php
```

**Option 2: Just use the app**
- The database will auto-initialize when you:
  - Register a new user
  - Login
  - Access admin panel

## Step 4: Verify It's Working

1. **Check Railway Logs**
   - In your app service, go to **"Deployments"** tab
   - Click on the latest deployment
   - Look for logs that say: `Using PostgreSQL database (Railway)` or similar

2. **Test Database**
   - Go to admin settings
   - Save your Brevo API key and settings
   - Try registering a new user
   - Check logs - you should see settings being retrieved correctly

## Troubleshooting

### Database Not Connecting?
- Make sure PostgreSQL service is running (green status)
- Verify `DATABASE_URL` is in your app's environment variables
- Check Railway logs for connection errors

### Settings Not Saving?
- Make sure database is initialized (visit `/api/init.php`)
- Check that PostgreSQL service is linked to your app
- Verify `DATABASE_URL` is set correctly

### Still Using SQLite?
- If you see SQLite in logs, `DATABASE_URL` might not be set
- Double-check environment variables in Railway
- Redeploy your app after setting `DATABASE_URL`

## Quick Checklist

- [ ] PostgreSQL service added to Railway project
- [ ] Database service is running (green status)
- [ ] `DATABASE_URL` is visible in app service variables
- [ ] Database initialized (visit `/api/init.php` or register a user)
- [ ] Test: Save Brevo settings → Register user → Check logs ✅

## After Setup

Once PostgreSQL is set up:
1. Your Brevo settings will persist across deployments
2. User registrations will be saved permanently
3. All data will be stored in PostgreSQL (not lost on redeploy)

