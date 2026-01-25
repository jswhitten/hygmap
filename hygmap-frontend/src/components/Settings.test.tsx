import { describe, it, expect, beforeEach } from "vitest";
import "@testing-library/jest-dom/vitest";
import { render, screen, fireEvent, within, waitFor } from "@testing-library/react";
import Settings from "./Settings";
import { useAppStore, clearPersistedSettings } from "../state/store";

const getSettingsButton = () => screen.getByRole("button", { name: /^settings$/i });
const openSettingsPanel = async () => {
  fireEvent.click(getSettingsButton());
  return await screen.findByRole("dialog", { name: /settings panel/i });
};
const switchToTab = async (panel: HTMLElement, tabName: string) => {
  const tab = within(panel).getByRole("tab", { name: tabName });
  fireEvent.click(tab);
  await waitFor(() => {
    expect(tab).toHaveAttribute("aria-selected", "true");
  });
};

describe("Settings Component", () => {
  beforeEach(() => {
    clearPersistedSettings();
    // Reset store to default state before each test
    useAppStore.setState({
      settingsOpen: false,
      magLimit: 8,
      maxDistance: null,
      spectralFilter: [],
      constellationFilter: null,
      labelMagLimit: 2,
      showLabels: true,
      showSignals: false,
      signalTypeFilter: 'all',
      gridSettings: {
        visible: true,
        spacing: 10,
        showXY: true,
        show3D: false,
      },
      coordinateSystem: "cartesian",
      unit: "pc",
      printableView: false,
      stars: [],
      signals: [],
    });
  });

  describe("Panel Toggle", () => {
    it("should render settings button", () => {
      render(<Settings />);
      const button = getSettingsButton();
      expect(button).toBeInTheDocument();
    });

    it("should open settings panel when button clicked", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      expect(panel).toBeInTheDocument();
    });

    it("should close settings panel when close button clicked", async () => {
      render(<Settings />);

      const panel = await openSettingsPanel();
      const closeButton = within(panel).getByRole("button", { name: /close settings/i });
      fireEvent.click(closeButton);

      await waitFor(() => {
        expect(screen.queryByRole("dialog", { name: /settings panel/i })).not.toBeInTheDocument();
      });
    });

    it("should have proper ARIA attributes", async () => {
      render(<Settings />);
      const button = getSettingsButton();

      expect(button).toHaveAttribute("aria-label", "Settings");
      expect(button).toHaveAttribute("aria-expanded", "false");

      await openSettingsPanel();
      expect(button).toHaveAttribute("aria-expanded", "true");
    });
  });

  describe("Magnitude Limit Input", () => {
    it("should display current magnitude limit", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const input = within(panel).getByLabelText(/star magnitude limit/i) as HTMLInputElement;
      expect(input.value).toBe("8");
    });

    it("should update magnitude limit when changed", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const input = within(panel).getByLabelText(/star magnitude limit/i);
      fireEvent.change(input, { target: { value: "10" } });

      expect(useAppStore.getState().magLimit).toBe(10);
    });

    it("should enforce min/max constraints", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const input = within(panel).getByLabelText(/star magnitude limit/i) as HTMLInputElement;
      expect(input).toHaveAttribute("min", "-2");
      expect(input).toHaveAttribute("max", "20");
    });
  });

  describe("Distance Filter", () => {
    it("should allow setting maximum distance", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const input = within(panel).getByLabelText(/maximum distance/i);
      fireEvent.change(input, { target: { value: "100" } });

      expect(useAppStore.getState().maxDistance).toBe(100);
    });

    it("should allow clearing maximum distance", async () => {
      useAppStore.setState({ maxDistance: 100 });

      render(<Settings />);
      const panel = await openSettingsPanel();
      const input = within(panel).getByLabelText(/maximum distance/i);
      fireEvent.change(input, { target: { value: "" } });

      expect(useAppStore.getState().maxDistance).toBeNull();
    });
  });

  describe("Spectral Type Filter", () => {
    it("should toggle spectral type buttons", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const oButton = within(panel).getByRole("button", { name: "O" });
      fireEvent.click(oButton);

      expect(useAppStore.getState().spectralFilter).toContain("O");

      fireEvent.click(oButton);
      expect(useAppStore.getState().spectralFilter).not.toContain("O");
    });

    it("should allow selecting multiple spectral types", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const oButton = within(panel).getByRole("button", { name: "O" });
      const bButton = within(panel).getByRole("button", { name: "B" });

      fireEvent.click(oButton);
      fireEvent.click(bButton);

      const filter = useAppStore.getState().spectralFilter;
      expect(filter).toContain("O");
      expect(filter).toContain("B");
      expect(filter).toHaveLength(2);
    });
  });

  describe("Grid Settings", () => {
    it("should toggle grid visibility", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const checkbox = within(panel).getByLabelText(/show grid/i) as HTMLInputElement;
      expect(checkbox.checked).toBe(true);

      fireEvent.click(checkbox);
      expect(useAppStore.getState().gridSettings.visible).toBe(false);
    });

    it("should change grid spacing", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const input = within(panel).getByLabelText(/grid spacing/i);
      fireEvent.change(input, { target: { value: "20" } });

      expect(useAppStore.getState().gridSettings.spacing).toBe(20);
    });

    it("should toggle 3D grid", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const checkbox = within(panel).getByLabelText(/3d grid/i) as HTMLInputElement;
      expect(checkbox.checked).toBe(false);

      fireEvent.click(checkbox);
      expect(useAppStore.getState().gridSettings.show3D).toBe(true);
    });
  });

  describe("SETI Signals Toggle", () => {
    it("should toggle signal visibility", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const checkbox = within(panel).getByLabelText(/show seti signals/i) as HTMLInputElement;
      expect(checkbox.checked).toBe(false);

      fireEvent.click(checkbox);
      expect(useAppStore.getState().showSignals).toBe(true);
    });

    it("should change signal type filter", async () => {
      useAppStore.setState({ showSignals: true });
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const select = within(panel).getByLabelText(/signal type/i) as HTMLSelectElement;

      fireEvent.change(select, { target: { value: "transmit" } });
      expect(useAppStore.getState().signalTypeFilter).toBe("transmit");
    });
  });

  describe("Unit Toggle", () => {
    it("should toggle between parsecs and light-years", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const select = within(panel).getByLabelText(/distance unit/i) as HTMLSelectElement;

      expect(select.value).toBe("pc");

      fireEvent.change(select, { target: { value: "ly" } });
      expect(useAppStore.getState().unit).toBe("ly");

      fireEvent.change(select, { target: { value: "pc" } });
      expect(useAppStore.getState().unit).toBe("pc");
    });
  });

  describe("Printable View", () => {
    it("should toggle printable view mode", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const checkbox = within(panel).getByLabelText(/printable view/i) as HTMLInputElement;
      expect(checkbox.checked).toBe(false);

      fireEvent.click(checkbox);
      expect(useAppStore.getState().printableView).toBe(true);
    });
  });

  describe("Label Settings", () => {
    it("should toggle label visibility", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      await switchToTab(panel, "Display");
      const checkbox = within(panel).getByLabelText(/show star labels/i) as HTMLInputElement;
      expect(checkbox.checked).toBe(true);

      fireEvent.click(checkbox);
      expect(useAppStore.getState().showLabels).toBe(false);
    });

    it("should change label magnitude threshold", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      const input = within(panel).getByLabelText(/label magnitude limit/i);
      fireEvent.change(input, { target: { value: "3" } });

      expect(useAppStore.getState().labelMagLimit).toBe(3);
    });
  });

  describe("Form Accessibility", () => {
    it("should have proper label associations", async () => {
      render(<Settings />);
      const panel = await openSettingsPanel();
      expect(within(panel).getByLabelText(/star magnitude limit/i)).toBeInstanceOf(
        HTMLInputElement
      );
      expect(within(panel).getByLabelText(/maximum distance/i)).toBeInstanceOf(HTMLInputElement);
    });
  });
});
