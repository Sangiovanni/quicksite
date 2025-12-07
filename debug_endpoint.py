import requests
import json

# Test the changeFavicon endpoint
response = requests.post(
    'http://template.vitrine/management/changeFavicon',
    json={'imageName': 'test.png'},
    timeout=10
)

print(f"Status Code: {response.status_code}")
print(f"Content-Type: {response.headers.get('Content-Type', 'N/A')}")
print(f"\nRaw Response (first 1000 chars):")
print(response.text[:1000])
print(f"\n... (Total length: {len(response.text)} chars)")

# Try to parse as JSON
try:
    json_data = response.json()
    print("\n✅ JSON parsing successful:")
    print(json.dumps(json_data, indent=2))
except json.JSONDecodeError as e:
    print(f"\n❌ JSON parsing failed: {e}")
    print(f"Error at position {e.pos}")
    if e.pos < len(response.text):
        print(f"Context: {response.text[max(0, e.pos-50):e.pos+50]}")
