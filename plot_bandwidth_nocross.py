import base64
import bz2
import json
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

FILE_PATH = "test_nocross.bz64jsonl"

methods = []
throughputs = []

with open(FILE_PATH, "r") as f:
    for line in f:
        line = line.strip()
        if not line:
            continue
        try:
            decoded = bz2.decompress(base64.b64decode(line))
            data = json.loads(decoded)

            method = data.get("method", "unknown")
            probe = data.get("probe", [])

            if method == 'igi':
                continue

            psize = int(data.get("psize", 1200))
            psize_bits = psize * 8

            valid_gaps = [float(g) for g in probe if str(g).lstrip('-').isdigit() and float(g) > 0]
            if valid_gaps:
                total_time_us = sum(valid_gaps)
                thru_mbps = (psize_bits * len(valid_gaps)) / (total_time_us * 1e-6) / 1e6
            else:
                thru_mbps = 0.0

            full_names = {
                'pathchirp': 'PathChirp',
                'proposed': 'Proposed'
            }
            methods.append(full_names.get(method, method))
            throughputs.append(round(thru_mbps, 2))

            print(f"  {method}: {len(valid_gaps)} valid gaps, thru = {thru_mbps:.2f} Mbps")

        except Exception as e:
            print("Skipping line:", e)

if not throughputs:
    print("No data found. Run the experiment first with tag=test_nocross")
    exit()

expected_bw = 10.0

methods_plot = ["Expected"] + methods
throughputs_plot = [expected_bw] + throughputs

plt.figure(figsize=(8, 5))
method_colors = {'PathChirp': '#4CAF50', 'Proposed': '#9C27B0'}
colors = ['#2196F3'] + [method_colors.get(m, '#4CAF50') for m in methods]
bars = plt.bar(methods_plot, throughputs_plot, color=colors)

plt.xlabel("Bandwidth Estimation Method")
plt.ylabel("Available Bandwidth (Mbps)")
plt.title("End-to-End Bandwidth Estimation (No Cross Traffic)")

for i, v in enumerate(throughputs_plot):
    plt.text(i, v + 0.1, f"{v:.2f}", ha='center', fontsize=10)

plt.ylim(0, max(throughputs_plot) + 2)
plt.grid(axis='y', linestyle='--', alpha=0.6)
plt.tight_layout()
plt.savefig("bandwidth_plot_nocross.png", dpi=150)
print(f"\nPlot saved to bandwidth_plot_nocross.png")
