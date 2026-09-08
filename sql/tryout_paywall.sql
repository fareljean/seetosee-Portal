-- SeeToSee tryout paywall — run on Seeme DB (no migration framework in repo).
-- Do NOT invent Stripe secret keys. Replace price_REPLACE_* after Stripe Dashboard create.
-- Do NOT modify portal $24 or prefix $12 product rows.

-- ---------------------------------------------------------------------------
-- 1) Credits balance + idempotent grant ledger
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tryout_credits (
  user_id BIGINT UNSIGNED NOT NULL,
  balance INT NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_tryout_credits_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tryout_credit_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  amount INT NOT NULL,
  source_type VARCHAR(64) NOT NULL,
  source_id VARCHAR(128) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tryout_credit_grant (source_type, source_id),
  KEY idx_tryout_credit_grants_user (user_id),
  CONSTRAINT fk_tryout_credit_grants_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) product_catalog rows (adjust column list if live schema differs)
--    Inspect live columns first: DESCRIBE product_catalog;
-- ---------------------------------------------------------------------------
INSERT INTO product_catalog (
  product_id,
  name,
  product_type,
  amount_cents,
  currency,
  billing_interval,
  entitlement_key,
  stripe_price_id,
  stripe_price_env_key,
  status,
  display_order
) VALUES
(
  'tryout.pack.3',
  'Tryout pack — 3 credits',
  'payment',
  1000,
  'usd',
  NULL,
  NULL,
  'price_REPLACE_tryout_pack3',
  'STRIPE_TRYOUT_PACK3_PRICE_ID',
  'active',
  50
),
(
  'subscription.tryout.monthly',
  'Tryout monthly',
  'subscription',
  2500,
  'usd',
  'month',
  'tryout.active',
  'price_REPLACE_tryout_monthly',
  'STRIPE_TRYOUT_MONTHLY_PRICE_ID',
  'active',
  51
)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  product_type = VALUES(product_type),
  amount_cents = VALUES(amount_cents),
  currency = VALUES(currency),
  billing_interval = VALUES(billing_interval),
  entitlement_key = VALUES(entitlement_key),
  stripe_price_id = VALUES(stripe_price_id),
  stripe_price_env_key = VALUES(stripe_price_env_key),
  status = VALUES(status),
  display_order = VALUES(display_order);

-- ---------------------------------------------------------------------------
-- 3) After Stripe Dashboard prices exist, fill real IDs (example):
-- ---------------------------------------------------------------------------
-- UPDATE product_catalog
--   SET stripe_price_id = 'price_XXXX_REAL_PACK3'
--   WHERE product_id = 'tryout.pack.3';
-- UPDATE product_catalog
--   SET stripe_price_id = 'price_XXXX_REAL_MONTHLY'
--   WHERE product_id = 'subscription.tryout.monthly';
-- Or set STRIPE_TRYOUT_PACK3_PRICE_ID / STRIPE_TRYOUT_MONTHLY_PRICE_ID in Hostinger .env
-- (env wins when stripe_price_env_key is set and non-empty).
