import numpy as np
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt

input_gaps = np.linspace(10, 200, 20)

threshold = 100

output_gaps = []
for g in input_gaps:
    if g < threshold:
        output_gaps.append(g + (threshold - g) * 0.5)
    else:
        output_gaps.append(g)

output_gaps = np.array(output_gaps)

plt.figure(figsize=(8, 5))

plt.plot(input_gaps, output_gaps, marker='o', color='#E53935', label="Observed Output Gap")
plt.plot(input_gaps, input_gaps, linestyle='--', color='#1E88E5', label="Ideal (No Congestion)")

plt.axvline(x=threshold, color='#43A047', linestyle=':', linewidth=2, label="Available Bandwidth Point")

plt.fill_between(input_gaps, input_gaps, output_gaps,
                 where=(output_gaps > input_gaps), alpha=0.15, color='red',
                 label="Congestion Region")

plt.xlabel("Input Packet Gap (µs)")
plt.ylabel("Output Packet Gap (µs)")
plt.title("Initial Gap Increasing: Input Gap vs Observed Output Gap")

plt.legend()
plt.grid(True, alpha=0.3)
plt.tight_layout()
plt.savefig("igi_plot.png", dpi=150)
print("Plot saved to igi_plot.png")
