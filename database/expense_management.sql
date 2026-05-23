-- Đoạn code ma thuật dọn sạch ENUM cũ nếu có trước khi tạo mới
DROP TYPE IF EXISTS "user_status" CASCADE;
DROP TYPE IF EXISTS "wallet_type" CASCADE;
DROP TYPE IF EXISTS "transaction_type" CASCADE;
DROP TYPE IF EXISTS "transaction_status" CASCADE;
DROP TYPE IF EXISTS "source_type" CASCADE;
DROP TYPE IF EXISTS "recurring_frequency" CASCADE;
DROP TYPE IF EXISTS "recurring_execution_status" CASCADE;
DROP TYPE IF EXISTS "notification_status" CASCADE;
DROP TYPE IF EXISTS "notification_channel" CASCADE;
DROP TYPE IF EXISTS "report_export_status" CASCADE;
DROP TYPE IF EXISTS "storage_provider_enum" CASCADE;

CREATE TYPE "user_status" AS ENUM (
  'active',
  'suspended'
);

CREATE TYPE "wallet_type" AS ENUM (
  'cash',
  'bank',
  'ewallet',
  'crypto'
);

CREATE TYPE "transaction_type" AS ENUM (
  'income',
  'expense'
);

CREATE TYPE "transaction_status" AS ENUM (
  'pending',
  'completed',
  'failed',
  'reverted'
);

CREATE TYPE "source_type" AS ENUM (
  'manual',
  'recurring',
  'transfer',
  'import'
);

CREATE TYPE "recurring_frequency" AS ENUM (
  'daily',
  'weekly',
  'monthly',
  'yearly'
);

CREATE TYPE "recurring_execution_status" AS ENUM (
  'success',
  'failed',
  'skipped'
);

CREATE TYPE "notification_status" AS ENUM (
  'pending',
  'sent',
  'failed'
);

CREATE TYPE "notification_channel" AS ENUM (
  'push',
  'email'
);

CREATE TYPE "report_export_status" AS ENUM (
  'pending',
  'processing',
  'completed',
  'failed'
);

CREATE TYPE "storage_provider_enum" AS ENUM (
  's3',
  'cloudinary',
  'firebase',
  'local'
);

CREATE TABLE "users" (
  "user_id" uuid PRIMARY KEY,
  "email" varchar UNIQUE,
  "status" user_status,
  "email_verified_at" timestamptz,
  "deleted_at" timestamptz,
  "created_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "user_credentials" (
  "user_id" uuid PRIMARY KEY,
  "password_hash" text,
  "password_changed_at" timestamptz,
  "failed_login_attempts" integer,
  "locked_until" timestamptz,
  "created_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "user_sessions" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "refresh_token_hash" text,
  "device_type" varchar(50),
  "device_name" varchar,
  "ip_address" inet,
  "user_agent" text,
  "expired_at" timestamptz,
  "revoked_at" timestamptz,
  "created_at" timestamptz
);

CREATE TABLE "oauth_accounts" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "provider" varchar(50),
  "provider_user_id" varchar,
  "created_at" timestamptz
);

CREATE TABLE "user_profiles" (
  "user_id" uuid PRIMARY KEY,
  "full_name" varchar,
  "avatar_url" text,
  "created_at" timestamptz
);

CREATE TABLE "user_preferences" (
  "user_id" uuid PRIMARY KEY,
  "language" varchar(10),
  "theme" varchar(20),
  "currency" varchar(10),
  "timezone" varchar,
  "financial_start_day" smallint,
  "created_at" timestamptz
);

CREATE TABLE "wallets" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "name" varchar,
  "type" wallet_type,
  "icon" varchar,
  "color" varchar,
  "is_hidden" bool,
  "created_at" timestamptz,
  "deleted_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "wallet_balances" (
  "wallet_id" uuid PRIMARY KEY,
  "available_balance" decimal(18,2),
  "version" integer,
  "last_transaction_id" uuid,
  "updated_at" timestamptz
);

CREATE TABLE "wallet_transfers" (
  "id" uuid PRIMARY KEY,
  "from_wallet_id" uuid,
  "to_wallet_id" uuid,
  "amount" decimal(18,2),
  "expense_transaction_id" uuid,
  "income_transaction_id" uuid,
  "transferred_at" timestamptz,
  "created_at" timestamptz
);

CREATE TABLE "categories" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "parent_id" uuid,
  "merge_to_category_id" uuid,
  "type" transaction_type,
  "name" varchar,
  "icon" varchar,
  "color" varchar,
  "sort_order" integer,
  "is_default" bool,
  "created_at" timestamptz,
  "deleted_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "transactions" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "wallet_id" uuid,
  "category_id" uuid,
  "type" transaction_type,
  "status" transaction_status,
  "amount" decimal(18,2),
  "currency_code" varchar(10),
  "exchange_rate" decimal(18,6),
  "title" varchar,
  "notes" text,
  "transaction_date" timestamptz,
  "source_type" source_type,
  "source_id" uuid,
  "created_at" timestamptz,
  "deleted_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "transaction_attachments" (
  "id" uuid PRIMARY KEY,
  "transaction_id" uuid,
  "storage_provider_enum" storage_provider_enum,
  "file_key" text,
  "file_url" text,
  "mime_type" varchar(50),
  "file_size" BIGINT,
  "uploaded_at" timestamptz
);

CREATE TABLE "transaction_audits" (
  "id" uuid PRIMARY KEY,
  "transaction_id" uuid,
  "old_data" jsonb,
  "new_data" jsonb,
  "changed_by" uuid,
  "created_at" timestamptz
);

CREATE TABLE "recurring_rules" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "wallet_id" uuid,
  "category_id" uuid,
  "type" transaction_type,
  "amount" decimal(18,2),
  "title" varchar,
  "frequency" recurring_frequency,
  "interval_value" integer,
  "next_run_at" timestamptz,
  "end_at" timestamptz,
  "is_active" bool,
  "created_at" timestamptz,
  "deleted_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "recurring_executions" (
  "id" uuid PRIMARY KEY,
  "recurring_rule_id" uuid,
  "transaction_id" uuid,
  "executed_at" timestamptz,
  "status" recurring_execution_status,
  "error_message" text,
  "created_at" timestamptz
);

CREATE TABLE "budgets" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "category_id" uuid,
  "limit_amount" decimal(18,2),
  "month" smallint,
  "year" integer,
  "created_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "budget_usages" (
  "budget_id" uuid PRIMARY KEY,
  "used_amount" decimal(18,2),
  "updated_at" timestamptz
);

CREATE TABLE "budget_alerts" (
  "id" uuid PRIMARY KEY,
  "budget_id" uuid,
  "threshold_percent" integer,
  "triggered_at" timestamptz
);

CREATE TABLE "notifications" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "type" varchar(50),
  "title" varchar,
  "content" text,
  "metadata" jsonb,
  "read_at" timestamptz,
  "created_at" timestamptz,
  "updated_at" timestamptz
);

CREATE TABLE "notification_preferences" (
  "user_id" uuid PRIMARY KEY,
  "email_enabled" bool,
  "push_enabled" bool,
  "weekly_summary_enabled" bool,
  "created_at" timestamptz
);

CREATE TABLE "notification_deliveries" (
  "id" uuid PRIMARY KEY,
  "notification_id" uuid,
  "channel" notification_channel,
  "status" notification_status,
  "sent_at" timestamptz,
  "failure_reason" text
);

CREATE TABLE "daily_statistics" (
  "user_id" uuid,
  "date" date,
  "income" decimal,
  "expense" decimal,
  "updated_at" timestamptz,
  PRIMARY KEY ("user_id", "date")
);

CREATE TABLE "monthly_statistics" (
  "user_id" uuid,
  "month" smallint,
  "year" integer,
  "income" decimal,
  "expense" decimal,
  "updated_at" timestamptz,
  PRIMARY KEY ("user_id", "month", "year")
);

CREATE TABLE "category_statistics" (
  "user_id" uuid,
  "category_id" uuid,
  "month" smallint,
  "year" integer,
  "total_amount" decimal,
  "updated_at" timestamptz,
  PRIMARY KEY ("user_id", "category_id", "month", "year")
);

CREATE TABLE "report_exports" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "type" varchar(50),
  "status" report_export_status,
  "file_url" text,
  "filters" jsonb,
  "exported_at" timestamptz,
  "created_at" timestamptz
);

CREATE TABLE "import_jobs" (
  "id" uuid PRIMARY KEY,
  "user_id" uuid,
  "file_url" text,
  "status" varchar(50),
  "success_rows" integer,
  "failed_rows" integer,
  "total_rows" integer,
  "created_at" timestamptz,
  "updated_at" timestamptz,
  "error_file_url" text
);

CREATE INDEX ON "user_sessions" ("user_id");
CREATE INDEX ON "user_sessions" ("expired_at");
CREATE UNIQUE INDEX ON "oauth_accounts" ("provider", "provider_user_id");
CREATE INDEX ON "categories" ("parent_id");
CREATE INDEX ON "transactions" ("user_id");
CREATE INDEX ON "transactions" ("wallet_id");
CREATE INDEX ON "transactions" ("category_id");
CREATE INDEX ON "transactions" ("transaction_date");
CREATE INDEX ON "transactions" ("user_id", "transaction_date");
CREATE INDEX ON "recurring_rules" ("next_run_at");
CREATE UNIQUE INDEX ON "budgets" ("user_id", "category_id", "month", "year");
CREATE INDEX ON "notifications" ("user_id", "created_at");

COMMENT ON COLUMN "user_credentials"."failed_login_attempts" IS 'failed_login_attempts >= 0';
COMMENT ON COLUMN "user_preferences"."financial_start_day" IS 'BETWEEN 1 AND 31';
COMMENT ON COLUMN "wallet_transfers"."amount" IS 'amount > 0';
COMMENT ON COLUMN "categories"."sort_order" IS 'sort_order >= 0';
COMMENT ON TABLE "transactions" IS 'amount > 0';
COMMENT ON COLUMN "transactions"."amount" IS 'amount > 0';
COMMENT ON COLUMN "transactions"."exchange_rate" IS 'exchange_rate > 0';
COMMENT ON COLUMN "recurring_rules"."interval_value" IS 'interval_value > 0';
COMMENT ON COLUMN "budgets"."limit_amount" IS 'limit_amount > 0';
COMMENT ON COLUMN "budget_alerts"."threshold_percent" IS 'threshold_percent > 0';
COMMENT ON COLUMN "import_jobs"."success_rows" IS 'success_rows >= 0';
COMMENT ON COLUMN "import_jobs"."failed_rows" IS 'failed_rows >= 0';
COMMENT ON COLUMN "import_jobs"."total_rows" IS 'total_rows >= 0';

ALTER TABLE "user_credentials" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_sessions" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "oauth_accounts" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_profiles" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "user_preferences" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallets" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallet_balances" ADD FOREIGN KEY ("wallet_id") REFERENCES "wallets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallet_transfers" ADD FOREIGN KEY ("from_wallet_id") REFERENCES "wallets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallet_transfers" ADD FOREIGN KEY ("to_wallet_id") REFERENCES "wallets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallet_transfers" ADD FOREIGN KEY ("expense_transaction_id") REFERENCES "transactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallet_transfers" ADD FOREIGN KEY ("income_transaction_id") REFERENCES "transactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "categories" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "categories" ADD FOREIGN KEY ("parent_id") REFERENCES "categories" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transactions" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transactions" ADD FOREIGN KEY ("wallet_id") REFERENCES "wallets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transactions" ADD FOREIGN KEY ("category_id") REFERENCES "categories" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction_attachments" ADD FOREIGN KEY ("transaction_id") REFERENCES "transactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "recurring_rules" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "recurring_rules" ADD FOREIGN KEY ("wallet_id") REFERENCES "wallets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "recurring_rules" ADD FOREIGN KEY ("category_id") REFERENCES "categories" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "recurring_executions" ADD FOREIGN KEY ("recurring_rule_id") REFERENCES "recurring_rules" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "recurring_executions" ADD FOREIGN KEY ("transaction_id") REFERENCES "transactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "budgets" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "budgets" ADD FOREIGN KEY ("category_id") REFERENCES "categories" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "budget_usages" ADD FOREIGN KEY ("budget_id") REFERENCES "budgets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "budget_alerts" ADD FOREIGN KEY ("budget_id") REFERENCES "budgets" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "notifications" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "notification_preferences" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "notification_deliveries" ADD FOREIGN KEY ("notification_id") REFERENCES "notifications" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "daily_statistics" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "monthly_statistics" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "category_statistics" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "category_statistics" ADD FOREIGN KEY ("category_id") REFERENCES "categories" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "report_exports" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction_audits" ADD FOREIGN KEY ("transaction_id") REFERENCES "transactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "transaction_audits" ADD FOREIGN KEY ("changed_by") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "import_jobs" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("user_id") DEFERRABLE INITIALLY IMMEDIATE;
ALTER TABLE "wallet_balances" ADD FOREIGN KEY ("last_transaction_id") REFERENCES "transactions" ("id") DEFERRABLE INITIALLY IMMEDIATE;