# PayMongo Integration Setup Guide

This guide explains how to set up PayMongo (sandbox) for GCash payments in AquaSphere.

## Overview

PayMongo is integrated to handle GCash payments. The system uses PayMongo's sandbox mode for testing, which is free to use.

## Setup Steps

### 1. Create PayMongo Account

1. Go to [PayMongo](https://paymongo.com/) and sign up for an account
2. Navigate to the Dashboard
3. Go to **Developers** > **API Keys**
4. Copy your **Secret Key** (starts with `sk_test_` for sandbox)

### 2. Configure Environment Variables

Add your PayMongo secret key to your environment variables:

**For Local Development:**
1. Create a `.env` file in the project root (same directory as `api/` folder)
2. Add the following line (replace with your actual secret key):
   ```
   PAYMONGO_SECRET_KEY=sk_test_your_secret_key_here
   ```
3. The `.env` file is already in `.gitignore` so it won't be committed to git
4. The system will automatically load this file when API endpoints are called

**For Railway/Production (Hosted System):**
1. Go to [Railway Dashboard](https://railway.app)
2. Open your AquaSphere project
3. Click on your **main application service** (the one running PHP, not the database)
4. Go to the **"Variables"** tab
5. Click **"+ New Variable"** or **"Raw Editor"**
6. Add the following:
   - **Name:** `PAYMONGO_SECRET_KEY`
   - **Value:** Your PayMongo secret key (e.g., `sk_test_...` for sandbox)
7. Click **Add** or **Save**
8. Railway will automatically redeploy your application with the new environment variable
9. Wait for deployment to complete (usually 1-2 minutes)

**Important:** After adding the variable, Railway will redeploy. Once deployment is complete, the PayMongo integration will be ready to use!

**Important Security Notes:**
- Never commit your `.env` file to git (it's already in `.gitignore`)
- Never share your secret key publicly
- Use sandbox keys (`sk_test_...`) for testing
- Use live keys (`sk_live_...`) only in production

### 3. Set Up Webhook (Optional but Recommended)

1. In PayMongo Dashboard, go to **Developers** > **Webhooks**
2. Click **Add Webhook**
3. Set the webhook URL to: `https://your-domain.com/api/paymongo_webhook.php`
4. Select events to listen for:
   - `source.chargeable` (required)
   - `payment.paid` (optional, for additional confirmation)
5. Save the webhook

**Note:** For local development, you can use a tool like [ngrok](https://ngrok.com/) to expose your local server:
```bash
ngrok http 8080
# Use the ngrok URL for webhook: https://your-ngrok-url.ngrok.io/api/paymongo_webhook.php
```

### 4. Test the Integration

1. Go to the payment page in your application
2. Select **GCash** as payment method
3. Click **Place Order**
4. You'll be redirected to PayMongo's test payment page
5. Use PayMongo's test credentials to complete the payment

## How It Works

### Payment Flow

1. **User selects GCash** → Frontend calls `api/create_order.php` to create order
2. **Order created** → Frontend calls `api/create_payment.php` to create PayMongo payment source
3. **PayMongo returns checkout URL** → User is redirected to PayMongo checkout page
4. **User completes payment** → PayMongo redirects back to your site
5. **Webhook received** → PayMongo sends webhook to `api/paymongo_webhook.php`
6. **Order status updated** → System marks order as "paid" or "payment_failed"

### API Endpoints

- **`POST /api/create_payment.php`** - Creates a PayMongo payment source
  - Body: `{ "amount": 100.00, "order_id": 123, "redirect_url": "https://..." }`
  - Returns: `{ "success": true, "checkout_url": "...", "source_id": "..." }`

- **`POST /api/paymongo_webhook.php`** - Handles PayMongo webhook callbacks
  - Automatically processes payment status updates
  - Updates order status in database

- **`POST /api/create_order.php`** - Creates an order in the database
  - Body: `{ "user_id": 1, "items": [...], "delivery_address": {...}, "payment_method": "GCASH" }`
  - Returns: `{ "success": true, "order_id": 123, "total_amount": 100.00 }`

## Database Schema

The system automatically adds a `paymongo_source_id` column to the `orders` table to track PayMongo payment sources.

## Testing

### Sandbox Mode

- PayMongo sandbox is free to use
- Test payments don't charge real money
- Use test credentials provided by PayMongo

### Test Payment Flow

1. Select GCash payment method
2. Complete checkout on PayMongo test page
3. Verify order status updates in your database
4. Check webhook logs in PayMongo dashboard

## Troubleshooting

### Payment Not Redirecting

- Check that `PAYMONGO_SECRET_KEY` is set correctly
- Verify the secret key starts with `sk_test_` for sandbox
- Check browser console for errors

### Webhook Not Working

- Verify webhook URL is accessible (use ngrok for local testing)
- Check PayMongo dashboard for webhook delivery status
- Review server logs for webhook processing errors

### Order Status Not Updating

- Ensure webhook is properly configured
- Check database connection
- Verify `paymongo_source_id` column exists in orders table

## Production Deployment

When moving to production:

1. Switch to PayMongo **Live Mode**
2. Update `PAYMONGO_SECRET_KEY` to live key (starts with `sk_live_`)
3. Update webhook URL to production domain
4. Test payment flow thoroughly before going live

## Support

For PayMongo API documentation, visit: https://developers.paymongo.com/

For issues with this integration, check:
- Server error logs
- PayMongo dashboard webhook logs
- Browser console for frontend errors

