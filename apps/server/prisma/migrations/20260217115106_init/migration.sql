-- CreateEnum
CREATE TYPE "public"."TransactionType" AS ENUM ('inc', 'exp');

-- CreateEnum
CREATE TYPE "public"."SubscriptionStatus" AS ENUM ('trial', 'active', 'past_due', 'canceled', 'expired');

-- CreateEnum
CREATE TYPE "public"."PaymentStatus" AS ENUM ('pending', 'succeeded', 'failed', 'refunded');

-- CreateTable
CREATE TABLE "public"."users" (
    "id" BIGINT NOT NULL,
    "chatId" BIGINT NOT NULL,
    "state" TEXT NOT NULL DEFAULT 'MAIN_MENU',
    "lastMsgId" BIGINT,
    "tempData" JSONB,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "users_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "public"."habits" (
    "id" SERIAL NOT NULL,
    "userId" BIGINT NOT NULL,
    "title" TEXT NOT NULL,
    "days" TEXT NOT NULL,
    "notify" BOOLEAN NOT NULL DEFAULT true,

    CONSTRAINT "habits_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "public"."goals" (
    "id" SERIAL NOT NULL,
    "userId" BIGINT NOT NULL,
    "title" TEXT NOT NULL,
    "category" TEXT,
    "deadline" TIMESTAMP(3),

    CONSTRAINT "goals_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "public"."goal_steps" (
    "id" SERIAL NOT NULL,
    "goalId" INTEGER NOT NULL,
    "stepText" TEXT NOT NULL,
    "isDone" BOOLEAN NOT NULL DEFAULT false,

    CONSTRAINT "goal_steps_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "public"."transactions" (
    "id" SERIAL NOT NULL,
    "userId" BIGINT NOT NULL,
    "type" "public"."TransactionType" NOT NULL,
    "amount" DECIMAL(10,2) NOT NULL,
    "category" TEXT NOT NULL,
    "goalId" INTEGER,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "transactions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "public"."subscriptions" (
    "id" TEXT NOT NULL,
    "userId" BIGINT NOT NULL,
    "planCode" TEXT NOT NULL,
    "status" "public"."SubscriptionStatus" NOT NULL,
    "trialUntil" TIMESTAMP(3),
    "expiresAt" TIMESTAMP(3),
    "provider" TEXT,
    "providerRef" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "subscriptions_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "public"."payments" (
    "id" TEXT NOT NULL,
    "userId" BIGINT NOT NULL,
    "provider" TEXT NOT NULL,
    "invoicePayload" TEXT NOT NULL,
    "telegramChargeId" TEXT,
    "providerChargeId" TEXT,
    "amount" DECIMAL(10,2) NOT NULL,
    "currency" TEXT NOT NULL,
    "status" "public"."PaymentStatus" NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "payments_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "habits_userId_idx" ON "public"."habits"("userId");

-- CreateIndex
CREATE INDEX "goals_userId_idx" ON "public"."goals"("userId");

-- CreateIndex
CREATE INDEX "goal_steps_goalId_idx" ON "public"."goal_steps"("goalId");

-- CreateIndex
CREATE INDEX "transactions_userId_createdAt_idx" ON "public"."transactions"("userId", "createdAt");

-- CreateIndex
CREATE INDEX "transactions_goalId_idx" ON "public"."transactions"("goalId");

-- CreateIndex
CREATE INDEX "subscriptions_userId_status_idx" ON "public"."subscriptions"("userId", "status");

-- CreateIndex
CREATE INDEX "payments_userId_status_idx" ON "public"."payments"("userId", "status");

-- AddForeignKey
ALTER TABLE "public"."habits" ADD CONSTRAINT "habits_userId_fkey" FOREIGN KEY ("userId") REFERENCES "public"."users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "public"."goals" ADD CONSTRAINT "goals_userId_fkey" FOREIGN KEY ("userId") REFERENCES "public"."users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "public"."goal_steps" ADD CONSTRAINT "goal_steps_goalId_fkey" FOREIGN KEY ("goalId") REFERENCES "public"."goals"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "public"."transactions" ADD CONSTRAINT "transactions_userId_fkey" FOREIGN KEY ("userId") REFERENCES "public"."users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "public"."transactions" ADD CONSTRAINT "transactions_goalId_fkey" FOREIGN KEY ("goalId") REFERENCES "public"."goals"("id") ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "public"."subscriptions" ADD CONSTRAINT "subscriptions_userId_fkey" FOREIGN KEY ("userId") REFERENCES "public"."users"("id") ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "public"."payments" ADD CONSTRAINT "payments_userId_fkey" FOREIGN KEY ("userId") REFERENCES "public"."users"("id") ON DELETE CASCADE ON UPDATE CASCADE;
