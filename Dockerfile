# Use official PHP image with PostgreSQL support
FROM php:8.2-cli

# Install PostgreSQL extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Python and pip for Python API
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy requirements file
COPY requirements.txt /app/requirements.txt

# Create virtual environment and install packages
RUN python3 -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"
RUN pip install --upgrade pip && \
    pip install --no-cache-dir -r /app/requirements.txt

# Copy all files
COPY . .

# Expose port (Railway will use PORT env var at runtime)
EXPOSE 8080

# Start PHP built-in server (Railway sets PORT env var)
# Python API is available but optional - can be enabled if needed
# For now, we'll focus on PHP as the main backend
CMD php -S 0.0.0.0:\${PORT:-8080} -t .

