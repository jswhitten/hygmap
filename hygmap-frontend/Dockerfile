# Development Dockerfile for React + Vite frontend
FROM node:20-alpine

WORKDIR /app

# Install dependencies first for better caching
COPY package*.json ./
RUN npm install

# Copy application code
COPY . .

# Expose Vite dev server port
EXPOSE 5173

# Run Vite dev server with host flag to accept connections from outside container
CMD ["npm", "run", "dev", "--", "--host"]
