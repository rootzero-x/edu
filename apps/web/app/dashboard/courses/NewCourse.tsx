"use client";

import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { Button, usePrompt, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";

export default function NewCourse() {
  const router = useRouter();
  const prompt = usePrompt();
  const toast = useToast();
  return (
    <Button
      icon={<Plus />}
      onClick={() => prompt({
        title: "Yangi kurs",
        fields: [
          { name: "title", label: "Kurs nomi", required: true },
          { name: "description", label: "Qisqa tavsif", type: "textarea" },
        ],
        submitText: "Yaratish",
        onSubmit: async (v) => {
          if (!v.title) return "Kurs nomini kiriting.";
          const r = await call("POST", "courses", { title: v.title, description: v.description || null });
          if (!r.ok) return errorText(r.data);
          toast.success("Kurs yaratildi", v.title);
          router.push(`/dashboard/courses/${r.data.course.id}`);
        },
      })}
    >
      Yangi kurs
    </Button>
  );
}
