import json
with open(r'C:\Users\ahmed\.gemini\antigravity\brain\02ad7dff-6864-41fb-9ccc-8c02cfeaa855\.system_generated\logs\transcript.jsonl', 'r', encoding='utf-8') as f:
    for line in f:
        if 'Option C' in line:
            data = json.loads(line)
            print(f"Source: {data.get('source')}, Type: {data.get('type')}")
            content = data.get('content', '')
            if content:
                print(content)
