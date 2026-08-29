# Toolset for mining data out of published documents (court work schedules and
# the like). PDF is the primary format; the Office readers are the extension for
# courts that publish the same content as .docx/.xlsx.
#
# Debian trixie on purpose: the app runtime runs the same distribution, so the
# tool versions here are the ones the project already reasons about.
#
# Build (from the repository root):
#   docker build -t jakubboucek/pdftools docker/pdftools
FROM debian:trixie-slim

# hadolint ignore=DL3008 - distribution versions are the point, see above.
RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        # PDF: text with layout, page rendering, images, metadata
        poppler-utils \
        # OCR for scanned documents, Czech and English models
        ocrmypdf \
        tesseract-ocr \
        tesseract-ocr-ces \
        tesseract-ocr-eng \
        # PDF repair and rasterisation helpers used by ocrmypdf and by hand
        ghostscript \
        qpdf \
        img2pdf \
        # Office formats: structure-aware readers (tables stay tables)
        python3 \
        python3-docx \
        python3-openpyxl \
        python3-xlrd \
        python3-odf \
        # Office formats: legacy binary and plain-text fallbacks
        antiword \
        catdoc \
        docx2txt \
        odt2txt \
        unrtf \
    && rm -rf /var/lib/apt/lists/*

COPY bin/ /usr/local/bin/
RUN chmod +x /usr/local/bin/pdf-*

# Documents are mounted here; every command takes paths relative to it.
WORKDIR /work

CMD ["pdf-help"]
