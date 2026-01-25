# Custom PostgreSQL image with AT-HYG star data
# Downloads CSV files during build so container is ready to initialize

FROM postgres:15

# Install wget for downloading CSV files
RUN apt-get update && apt-get install -y wget && rm -rf /var/lib/apt/lists/*

# Create data directory
RUN mkdir -p /data

# Download AT-HYG CSV files (compressed, ~100MB total download)
RUN wget -q -O /data/athyg_v33-1.csv.gz \
    https://codeberg.org/astronexus/athyg/media/branch/main/data/athyg_v33-1.csv.gz && \
    wget -q -O /data/athyg_v33-2.csv.gz \
    https://codeberg.org/astronexus/athyg/media/branch/main/data/athyg_v33-2.csv.gz && \
    gunzip /data/athyg_v33-1.csv.gz && \
    gunzip /data/athyg_v33-2.csv.gz

# Copy local data files (supplement, fictional names, signals)
COPY data/athyg_supplement.csv /data/
COPY data/athyg_tycho_trek.csv /data/
COPY data/athyg_tycho_b5.csv /data/
COPY data/signals.csv /data/

# Copy initialization scripts (run in alphabetical order on first start)
COPY sql/ /docker-entrypoint-initdb.d/

# Set proper permissions
RUN chmod 644 /data/*.csv && \
    chmod 644 /docker-entrypoint-initdb.d/*.sql
