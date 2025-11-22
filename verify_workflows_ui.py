import re

with open('index.php', 'r') as f:
    content = f.read()

checks = [
    "Automate Your Business",
    "Start with a Template",
    "bg-gradient-to-r from-violet-600",
    "No Workflows Yet",
    "useWorkflowTemplateById(${t.id})"
]

print("Verifying UI changes in index.php...")
all_passed = True
for check in checks:
    if check in content:
        print(f"[PASS] Found: '{check}'")
    else:
        print(f"[FAIL] Missing: '{check}'")
        all_passed = False

if all_passed:
    print("\nAll UI checks passed successfully.")
else:
    print("\nSome UI checks failed.")
