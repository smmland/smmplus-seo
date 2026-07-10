-- CreateEnum
CREATE TYPE "UrlPatternType" AS ENUM ('HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER');

-- CreateEnum
CREATE TYPE "SyncStatus" AS ENUM ('RUNNING', 'SUCCESS', 'FAILED');

-- CreateTable
CREATE TABLE "Url" (
    "id" TEXT NOT NULL,
    "sourceUrl" TEXT NOT NULL,
    "path" TEXT NOT NULL,
    "lang" TEXT NOT NULL,
    "patternType" "UrlPatternType" NOT NULL,
    "slug" TEXT NOT NULL,
    "groupKey" TEXT NOT NULL,
    "sourceLastmod" TIMESTAMP(3),
    "priority" DOUBLE PRECISION,
    "changefreq" TEXT,
    "isHidden" BOOLEAN NOT NULL DEFAULT false,
    "isManual" BOOLEAN NOT NULL DEFAULT false,
    "isActive" BOOLEAN NOT NULL DEFAULT true,
    "firstSeenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "lastSeenAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Url_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "SyncRun" (
    "id" TEXT NOT NULL,
    "status" "SyncStatus" NOT NULL DEFAULT 'RUNNING',
    "startedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "finishedAt" TIMESTAMP(3),
    "totalFetched" INTEGER,
    "added" INTEGER,
    "updated" INTEGER,
    "removed" INTEGER,
    "errorMessage" TEXT,

    CONSTRAINT "SyncRun_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Setting" (
    "key" TEXT NOT NULL,
    "value" TEXT NOT NULL,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Setting_pkey" PRIMARY KEY ("key")
);

-- CreateTable
CREATE TABLE "AdminUser" (
    "id" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "passwordHash" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "AdminUser_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "Url_sourceUrl_key" ON "Url"("sourceUrl");

-- CreateIndex
CREATE INDEX "Url_patternType_idx" ON "Url"("patternType");

-- CreateIndex
CREATE INDEX "Url_groupKey_idx" ON "Url"("groupKey");

-- CreateIndex
CREATE INDEX "Url_isHidden_idx" ON "Url"("isHidden");

-- CreateIndex
CREATE INDEX "Url_isActive_idx" ON "Url"("isActive");

-- CreateIndex
CREATE INDEX "SyncRun_startedAt_idx" ON "SyncRun"("startedAt");

-- CreateIndex
CREATE UNIQUE INDEX "AdminUser_email_key" ON "AdminUser"("email");
