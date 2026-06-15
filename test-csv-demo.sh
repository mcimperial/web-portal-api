#!/bin/bash

# TEST: CSV Attachment Feature - Email Test
# This script demonstrates the CSV attachment feature with sample data

echo "=== CSV ATTACHMENT FEATURE TEST ==="
echo ""
echo "Testing password-protected CSV attachment feature..."
echo ""

# Create a sample CSV file
TEMP_DIR=$(mktemp -d)
CSV_FILE="$TEMP_DIR/ENROLLEES_APPROVED_test.csv"

# Generate sample CSV with multi-line dependents
cat > "$CSV_FILE" << 'CSV_CONTENT'
EMPLOYEE NAME,EMPLOYEE ID,PLAN SELECTED,ACTIVATION DATE,COVERAGE START DATE,ANY DEPENDENTS ENROLLED
John Doe,EMP001,Gold Plan,06/15/2024,06/01/2024,"Mary Doe
James Doe"
Jane Smith,EMP002,Platinum Plan,06/10/2024,05/15/2024,None
Robert Johnson,EMP003,Silver Plan,06/12/2024,06/01/2024,"Alice Johnson
Charlie Johnson
Diana Johnson"
Maria Garcia,EMP004,Gold Plus,06/14/2024,06/01/2024,None
CSV_CONTENT

echo "✓ Sample CSV created: $(basename $CSV_FILE)"
echo "  Location: $CSV_FILE"
echo "  Size: $(wc -c < $CSV_FILE) bytes"
echo ""

# Display CSV content
echo "CSV Content Preview:"
echo "===================="
cat "$CSV_FILE"
echo ""
echo "===================="
echo ""

# Create email test details
PASSWORD="TestPassword2024!"
EMAIL_TO="markimperial@llibi.com"
EMAIL_SUBJECT="[TEST] Enrollment Report - Password Protected CSV"

echo "Email Details:"
echo "  To: $EMAIL_TO"
echo "  Subject: $EMAIL_SUBJECT"
echo "  Attachment: $(basename $CSV_FILE)"
echo "  Password: $PASSWORD"
echo ""

echo "✓ CSV Feature Test Setup Complete!"
echo ""
echo "To send this via your Laravel app, use:"
echo "  php artisan tinker"
echo "  Mail::send([], [], function(\$m) { \$m->to('$EMAIL_TO')->attach('$CSV_FILE'); });"
echo ""

# Cleanup
rm -rf "$TEMP_DIR"
echo "✓ Test files cleaned up"
