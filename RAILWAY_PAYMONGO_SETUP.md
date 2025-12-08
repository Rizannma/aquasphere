# Quick PayMongo Setup for Railway (Hosted System)

Since you're testing on Railway, you need to set the environment variable in Railway's dashboard, not in a local `.env` file.

## Step-by-Step Instructions

### Step 1: Open Railway Dashboard
1. Go to [railway.app](https://railway.app)
2. Log in to your account
3. Open your **AquaSphere** project

### Step 2: Add PayMongo Secret Key
1. Click on your **main application service** (the PHP service, not PostgreSQL)
2. Click on the **"Variables"** tab
3. Click **"+ New Variable"** button
4. Enter:
   - **Variable Name:** `PAYMONGO_SECRET_KEY`
   - **Value:** Your PayMongo secret key (starts with `sk_test_` for sandbox)
5. Click **Add**

### Step 3: Wait for Redeployment
- Railway will automatically detect the new environment variable
- Your application will automatically redeploy
- Wait 1-2 minutes for deployment to complete
- You'll see a green checkmark when it's done

### Step 4: Verify It's Working
1. Go to your hosted payment page
2. Select **GCash** as payment method
3. Click **Place Order**
4. You should be redirected to PayMongo's checkout page

## Visual Guide

```
Railway Dashboard
  └── Your Project (AquaSphere)
      └── Main Service (PHP Application)
          └── Variables Tab
              └── + New Variable
                  ├── Name: PAYMONGO_SECRET_KEY
                  └── Value: sk_test_your_secret_key_here
```

## Troubleshooting

### Variable Not Working?
1. Make sure you added it to the **main application service**, not the database
2. Check that the variable name is exactly: `PAYMONGO_SECRET_KEY` (case-sensitive)
3. Verify the value starts with `sk_test_`
4. Check Railway logs for any errors

### How to Check if Variable is Set
1. Go to your service → Variables tab
2. You should see `PAYMONGO_SECRET_KEY` in the list
3. The value will be hidden (showing as `••••••••`)

### Still Not Working?
1. Check Railway deployment logs
2. Look for errors related to PayMongo
3. Verify the secret key is correct in PayMongo dashboard
4. Make sure you're using the sandbox key (`sk_test_...`)

## Next Steps

After setting the variable:
1. ✅ Wait for Railway to redeploy (1-2 minutes)
2. ✅ Test the payment flow on your hosted site
3. ✅ (Optional) Set up webhook in PayMongo dashboard

## Important Notes

- **Never commit secret keys to git** - that's why we use Railway's environment variables
- The `.env` file is only for **local development**
- Railway environment variables persist across all deployments
- You can update the variable anytime in Railway dashboard

