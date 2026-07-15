FROM node:20-slim AS builder
RUN apt-get update -y && apt-get install -y openssl

WORKDIR /app

# Copy root package.json and lockfile
COPY package*.json ./

# Copy workspace package.json files so npm install knows about the workspaces
COPY packages/shared/package*.json ./packages/shared/
COPY apps/api/package*.json ./apps/api/

# Install dependencies for the backend workspaces
RUN npm install

# Copy all source files
COPY . .

# Generate Prisma Client
RUN npm run prisma:generate

# Build the shared library
RUN npm run build --workspace=@erp-ai/shared

# Build the NestJS API
RUN npm run build --workspace=@erp-ai/api

# Create the final production image
FROM node:20-slim AS runner
RUN apt-get update -y && apt-get install -y openssl

WORKDIR /app

# Copy package files
COPY package*.json ./
COPY packages/shared/package*.json ./packages/shared/
COPY apps/api/package*.json ./apps/api/

# Install only production dependencies
RUN npm install --omit=dev
RUN npm install -g prisma

# Copy the built shared library
COPY --from=builder /app/packages/shared/dist ./packages/shared/dist

# Copy the built API and Prisma files
COPY --from=builder /app/apps/api/dist ./apps/api/dist
COPY --from=builder /app/apps/api/prisma ./apps/api/prisma
COPY --from=builder /app/node_modules/@prisma/client ./node_modules/@prisma/client
COPY --from=builder /app/node_modules/.prisma ./node_modules/.prisma

# Expose the port
EXPOSE 4000

# Set production environment
ENV NODE_ENV=production

# Start script: Run migrations then start the server
CMD ["sh", "-c", "npx prisma migrate deploy --schema=./apps/api/prisma/schema.prisma && node apps/api/dist/apps/api/src/scripts/seed-admin.js || true && node apps/api/dist/apps/api/src/main.js"]
