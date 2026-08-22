-- Pass 9.7.3 keeps email headers as PostgreSQL text so real-world headers are preserved.
ALTER TABLE "ShowEmailIntakes" ALTER COLUMN "ToAddress" TYPE text;
ALTER TABLE "ShowEmailIntakes" ALTER COLUMN "FromAddress" TYPE text;
ALTER TABLE "ShowEmailIntakes" ALTER COLUMN "Subject" TYPE text;
